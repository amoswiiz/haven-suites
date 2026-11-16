<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\PublicAboutTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportWebsitePagesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->company = Tenant::factory()->create(['slug' => 'pages-stays', 'name' => 'Haven Suites', 'active' => true]);
        $admin = User::factory()->create(['active' => true]);
        DB::table('public_sites')->insert(['tenant_id' => $this->company->id, 'enabled' => true, 'enquiry_assignee_id' => $admin->id, 'response_hours' => 2,
            'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('public_posts')->insert(['tenant_id' => $this->company->id, 'slug' => 'nyali-guide', 'title' => 'Nyali guide', 'status' => 'published', 'published_at' => now(),
            'audience' => 'guests', 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
            'body' => 'See [business stays](https://havensuitesbnb.com/business-accommodation/), [about us](https://havensuitesbnb.com/about-haven-suites/) and [a missing page](https://havensuitesbnb.com/old-offer/).']);
        $pages = [
            'business-accommodation' => ['title' => 'Business Accommodation in Kenya | Executive Short Stays', 'date' => '2026-02-17T08:21:48',
                'html' => '<h2>Business Accommodation in Kenya</h2><p><img src="https://havensuitesbnb.com/wp-content/uploads/2026/02/desk.png" alt="Desk"></p>'
                    .'<h2>Why choose us</h2><p>Fast Wi-Fi, quiet rooms and secure buildings in Westlands, Riverside and Kilimani for consultants and NGO teams.</p>'
                    .'<p>Read about <a href="https://havensuitesbnb.com/airbnb-property-management/">management</a>.</p>'],
            'about-haven-suites' => ['title' => 'About Haven Suites', 'date' => '2025-02-20T10:01:05',
                'html' => '<h2>About Haven Suites</h2><h2>Experience the Epitome of Comfort and Convenience</h2><p>At <strong>Haven Suites</strong>, we offer serviced apartments.</p><p>Our prime locations are near the beach.</p>'
                    .'<h2>Meet our Team</h2><p>Your Dedicated Hospitality Team</p><img src="https://havensuitesbnb.com/wp-content/uploads/amos.png" alt="Amos">'
                    .'<p><strong>AMOS MWAURA</strong></p><p>Founder/CEO</p><p><strong>AMOS MWAURA</strong></p><p>Founder/CEO</p>'
                    .'<p>Amos Mwaura is the visionary behind Haven Suites, a premier Airbnb management and marketing company in Kenya.</p>'
                    .'<img src="https://example.org/loise.png" alt=""><p><strong>LOISE WANJIRU</strong></p><p>Customer Success Manager</p>'
                    .'<p>Loise Wanjiru is dedicated to ensuring exceptional guest experiences and seamless property management.</p>'
                    .'<h2>Why Choose Haven Suites?</h2><h3>Prime Locations:</h3><p>Close to dining and the beach.</p><h3>24/7 Support:</h3><p>Our team is available around the clock.</p>'],
        ];
        Http::fake([
            'havensuitesbnb.com/wp-json/wp/v2/pages*' => function (Request $request) use ($pages) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $page = $pages[$query['slug'] ?? ''] ?? null;

                return Http::response($page ? [['slug' => $query['slug'], 'date' => $page['date'], 'title' => ['rendered' => $page['title']],
                    'content' => ['rendered' => $page['html']], 'featured_media' => 0]] : []);
            },
            'havensuitesbnb.com/wp-content/uploads/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);
    }

    public function test_dry_run_reports_without_saving(): void
    {
        $this->artisan('haven:import-website-pages', ['tenant' => 'pages-stays', '--dry-run' => true])
            ->expectsOutputToContain('Would point 2 links to the old website in 1 articles to their new pages.')
            ->expectsOutputToContain('About page: would fill 2 introduction paragraphs, 2 reasons to choose you, 2 team members (Amos Mwaura, Loise Wanjiru).')
            ->assertSuccessful();
        $this->assertSame(1, DB::table('public_posts')->count());
        $this->assertNull(DB::table('public_sites')->value('about_intro'));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_service_pages_about_content_and_links_are_imported_once(): void
    {
        $this->artisan('haven:import-website-pages', ['tenant' => 'pages-stays', '--publish' => true])
            ->expectsOutputToContain('Pointed 2 links to the old website in 1 articles to their new pages.')->assertSuccessful();

        $page = DB::table('public_posts')->where('slug', 'business-accommodation')->first();
        $this->assertSame(['published', 'guests', 'Business Accommodation in Kenya | Executive Short Stays'], [$page->status, $page->audience, $page->title]);
        $this->assertStringStartsWith('![Desk](', $page->body, 'The repeated page title heading is removed.');
        $this->assertStringContainsString('## Why choose us', $page->body);
        $this->assertStringContainsString('[management](https://havensuitesbnb.com/airbnb-property-management/)', $page->body, 'Pages not on the new site keep their old link.');
        Storage::disk('local')->assertExists($page->cover_path);
        $this->assertSame(0, DB::table('public_posts')->where('slug', 'airbnb-property-management')->count(), 'Pages missing on the old site are skipped.');

        $guide = DB::table('public_posts')->where('slug', 'nyali-guide')->value('body');
        $this->assertStringContainsString('[business stays]('.route('public-stays.blog.show', ['company' => 'pages-stays', 'slug' => 'business-accommodation']).')', $guide);
        $this->assertStringContainsString('[about us]('.route('public-stays.about', 'pages-stays').')', $guide);
        $this->assertStringContainsString('[a missing page](https://havensuitesbnb.com/old-offer/)', $guide);

        $site = DB::table('public_sites')->first();
        $this->assertSame("At Haven Suites, we offer serviced apartments.\n\nOur prime locations are near the beach.", $site->about_intro);
        $this->assertSame("Prime Locations: Close to dining and the beach.\n24/7 Support: Our team is available around the clock.", $site->about_why);
        $team = PublicAboutTeam::saved($site);
        $this->assertSame(['Amos Mwaura', 'Loise Wanjiru'], array_column($team, 'name'));
        $this->assertSame('Founder/CEO', $team[0]['role']);
        $this->assertStringStartsWith('Amos Mwaura is the visionary', $team[0]['bio']);
        Storage::disk('local')->assertExists($team[0]['photo']);
        $this->assertNull($team[1]['photo'], 'Photos from other websites are not downloaded.');

        $this->get('/stays/pages-stays/team/'.basename($team[0]['photo']))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get('/stays/pages-stays/team/team-'.str_repeat('a', 16).'.jpg')->assertNotFound();

        DB::table('public_sites')->update(['about_intro' => 'Our own words.']);
        $this->artisan('haven:import-website-pages', ['tenant' => 'pages-stays'])
            ->expectsOutputToContain('About page: nothing to fill')->assertSuccessful();
        $this->assertSame('Our own words.', DB::table('public_sites')->value('about_intro'), 'Text staff have written is never replaced.');
        $this->assertSame(1, DB::table('public_posts')->where('slug', 'business-accommodation')->count());
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(400, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 80));
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
