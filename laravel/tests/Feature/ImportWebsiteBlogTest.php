<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\ListingPhotoVariants;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportWebsiteBlogTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $company;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->company = Tenant::factory()->create(['slug' => 'blog-stays', 'active' => true]);
        DB::table('public_posts')->insert(['tenant_id' => $this->company->id, 'slug' => 'already-here', 'title' => 'Written in the app', 'body' => 'Our own post.',
            'status' => 'published', 'published_at' => now(), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $html = '<p data-start="1">Riverside offers <strong>stability</strong> &amp; calm. Read <a href="https://havensuitesbnb.com/shanzu-vs-nyali/">Shanzu vs Nyali</a>'
            .' or <a href="https://example.org/report">this report</a>.</p><h2>1. Location</h2><ul><li><p>Westlands</p></li><li><p>Upper Hill</p></li></ul>'
            .'<figure><img src="https://havensuitesbnb.com/wp-content/uploads/2026/02/pool-1024x682.png" alt="The pool"><figcaption>Pool at sunset</figcaption></figure>'
            .'<p><img src="https://cdn.example.org/tracker.png" alt=""></p><script>alert(1)</script><p># Not a heading and *not bold*</p>';
        $posts = [
            ['id' => 11, 'slug' => 'already-here', 'date' => '2024-01-01T10:00:00', 'title' => ['rendered' => 'Old copy'], 'excerpt' => ['rendered' => ''],
                'content' => ['rendered' => '<p>Website copy that must not replace the app post.</p>'], 'featured_media' => 0],
            ['id' => 12, 'slug' => 'why-riverside-performs', 'date' => '2026-02-15T12:03:23', 'title' => ['rendered' => 'Why Riverside &#038; Westlands Perform'],
                'excerpt' => ['rendered' => '<p>Riverside has quietly become strong [&hellip;]</p>'], 'content' => ['rendered' => $html], 'featured_media' => 50],
            ['id' => 13, 'slug' => 'shanzu-vs-nyali', 'date' => '2026-02-14T09:00:00', 'title' => ['rendered' => 'Shanzu vs Nyali'], 'excerpt' => ['rendered' => ''],
                'content' => ['rendered' => '<p>Shanzu and Nyali compared for guests who want the beach and quiet evenings.</p>'], 'featured_media' => 0],
        ];
        Http::fake([
            'havensuitesbnb.com/wp-json/wp/v2/posts*' => Http::response($posts, 200, ['X-WP-Total' => '3', 'X-WP-TotalPages' => '1']),
            'havensuitesbnb.com/wp-json/wp/v2/media*' => Http::response([['id' => 50, 'source_url' => 'https://havensuitesbnb.com/wp-content/uploads/2026/02/cover.png']]),
            'havensuitesbnb.com/wp-content/uploads/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);
    }

    public function test_dry_run_converts_posts_without_saving_or_downloading(): void
    {
        $this->artisan('haven:import-website-blog', ['tenant' => 'blog-stays', '--dry-run' => true])
            ->expectsOutputToContain('Dry run: would import 2 of 3 posts. 1 already in the blog.')->assertSuccessful();

        $this->assertSame(1, DB::table('public_posts')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/wp-content/uploads/'));
    }

    public function test_posts_import_as_drafts_with_images_links_and_original_dates_and_never_overwrite(): void
    {
        $author = User::factory()->create();
        $author->tenants()->attach($this->company, ['roles' => json_encode(['admin']), 'legacy_user_id' => 1]);
        $this->artisan('haven:import-website-blog', ['tenant' => 'blog-stays', '--actor' => $author->id])
            ->expectsOutputToContain('Imported 2 of 3 posts. 1 already in the blog. 0 failed. 2 images saved, 1 left out.')->assertSuccessful();

        $this->assertSame('Our own post.', DB::table('public_posts')->where('slug', 'already-here')->value('body'), 'Existing posts are never changed.');
        $post = DB::table('public_posts')->where('slug', 'why-riverside-performs')->first();
        $this->assertSame('Why Riverside & Westlands Perform', $post->title);
        $this->assertSame('Riverside has quietly become strong', $post->excerpt);
        $this->assertSame('draft', $post->status);
        $this->assertSame($author->id, (int) $post->author_id);
        $this->assertSame('2026-02-15 09:03:23', $this->utc($post->published_at), 'Website dates are Nairobi time.');

        $this->assertStringContainsString('Riverside offers **stability** & calm. Read [Shanzu vs Nyali]('.route('public-stays.blog.show', ['company' => 'blog-stays', 'slug' => 'shanzu-vs-nyali']).')', $post->body);
        $this->assertStringContainsString('[this report](https://example.org/report)', $post->body);
        $this->assertStringContainsString("## 1. Location\n\n- Westlands\n- Upper Hill", $post->body);
        $this->assertMatchesRegularExpression('#!\[The pool\]\('.preg_quote(url('/stays/blog-stays/blog/images'), '#').'/([A-Za-z0-9]{32})\)\n\n\*Pool at sunset\*#', $post->body);
        $this->assertStringContainsString('\# Not a heading and \*not bold\*', $post->body);
        $this->assertStringNotContainsString('alert', $post->body);
        $this->assertStringNotContainsString('cdn.example.org', $post->body, 'Images from other sites are left out.');

        preg_match('#/blog/images/([A-Za-z0-9]{32})#', $post->body, $image);
        Storage::disk('local')->assertExists('public-posts/'.$this->company->id.'/inline/'.$image[1].'.jpg');
        $this->assertMatchesRegularExpression('#^public-posts/'.$this->company->id.'/[A-Za-z0-9]{32}\.jpg$#', $post->cover_path);
        Storage::disk('local')->assertExists($post->cover_path);
        foreach (ListingPhotoVariants::WIDTHS as $width) {
            Storage::disk('local')->assertExists(ListingPhotoVariants::key($post->cover_path, $width));
            Storage::disk('local')->assertExists(ListingPhotoVariants::key('public-posts/'.$this->company->id.'/inline/'.$image[1].'.jpg', $width));
        }

        $this->artisan('haven:import-website-blog', ['tenant' => 'blog-stays'])
            ->expectsOutputToContain('Imported 0 of 3 posts. 3 already in the blog.')->assertSuccessful();
        $this->assertSame(3, DB::table('public_posts')->count());
    }

    public function test_publish_option_publishes_with_original_dates_and_bad_actor_is_refused(): void
    {
        $this->artisan('haven:import-website-blog', ['tenant' => 'blog-stays', '--actor' => '999'])->assertFailed();
        $this->artisan('haven:import-website-blog', ['tenant' => 'missing-company'])->assertFailed();
        $this->assertSame(1, DB::table('public_posts')->count());

        $this->artisan('haven:import-website-blog', ['tenant' => 'blog-stays', '--publish' => true])->assertSuccessful();
        $this->assertSame(['published'], DB::table('public_posts')->where('slug', '!=', 'already-here')->distinct()->pluck('status')->all());
        $this->assertSame('2026-02-14 06:00:00', $this->utc(DB::table('public_posts')->where('slug', 'shanzu-vs-nyali')->value('published_at')));
    }

    private function utc(string $value): string
    {
        return CarbonImmutable::parse($value, config('app.timezone'))->utc()->format('Y-m-d H:i:s');
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(400, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 80, 160));
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
