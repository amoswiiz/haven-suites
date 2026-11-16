<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\BlogHtmlToMarkdown;
use App\Services\PublicAboutTeam;
use App\Services\PublicImageEncoder;
use App\Services\WebsiteBlogSource;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

#[Signature('haven:import-website-pages {tenant : Company slug} {--publish : Publish the service pages instead of saving drafts} {--dry-run : Show what would change without saving or downloading}')]
#[Description('Copy the service pages and About page content from the WordPress website, and point article links to the new pages')]
class ImportWebsitePages extends ImportWebsiteBlog
{
    /** Service pages on the old website and who they are written for. They keep their web address under /blog/. */
    public const SERVICE_PAGES = [
        'business-accommodation' => 'guests',
        'airbnb-property-management' => 'owners',
        'guest-communication-support' => 'owners',
        'housekeeping-maintenance-at-haven-suite-company' => 'owners',
        'revenue-management-dynamic-pricing-at-haven-suite-company' => 'owners',
        'owner-reporting-performance-analytics-at-haven-suite-bnb' => 'owners',
        'how-haven-suite-company-helps-you-optimize-and-market-your-property' => 'owners',
    ];

    public const ABOUT_PAGE = 'about-haven-suites';

    /** @var array<string, true> In a dry run, the service pages that would be imported, so the link count matches a real run. */
    private array $dryRunPages = [];

    public function handle(WebsiteBlogSource $source, BlogHtmlToMarkdown $converter, PublicImageEncoder $encoder): int
    {
        $tenant = Tenant::where('slug', $this->argument('tenant'))->where('active', true)->first();
        if (! $tenant) {
            $this->error('Choose an active company by its slug, for example: haven');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $failed = $this->importServicePages($tenant, $source, $converter, $encoder, $dryRun);
        $this->relinkArticles($tenant, $dryRun);
        $this->importAbout($tenant, $source, $converter, $encoder, $dryRun);
        if ($dryRun) {
            $this->line('Dry run: nothing was saved or downloaded.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function importServicePages(Tenant $tenant, WebsiteBlogSource $source, BlogHtmlToMarkdown $converter, PublicImageEncoder $encoder, bool $dryRun): bool
    {
        $existing = array_fill_keys(DB::table('public_posts')->where('tenant_id', $tenant->id)->pluck('slug')->all(), true);
        $rows = [];
        $failed = false;
        $found = [];
        foreach (self::SERVICE_PAGES as $slug => $audience) {
            if (isset($existing[$slug])) {
                $rows[$slug] = [$slug, 'Skipped: already in the blog'];
            } elseif ($page = $source->page($slug)) {
                $found[$slug] = $page;
            } else {
                $rows[$slug] = [$slug, 'Skipped: not found on the website'];
            }
        }
        // Links between service pages only point here for pages that exist on the new site after this import.
        $known = $existing + array_fill_keys(array_keys($found), true);
        if ($dryRun) {
            $this->dryRunPages = array_fill_keys(array_keys($found), true);
        }
        foreach ($found as $slug => $page) {
            $audience = self::SERVICE_PAGES[$slug];
            $this->newFiles = [];
            $this->newImageSources = [];
            try {
                $firstImage = null;
                $body = $converter->convert($page['html'],
                    function (string $src) use ($dryRun, $tenant, $source, $encoder, &$firstImage): ?string {
                        $firstImage ??= $source->isOwnSite($src) ? $src : null;

                        return $dryRun ? ($source->isOwnSite($src) ? $src : null) : $this->storeInlineImage($tenant, $src, $source, $encoder);
                    },
                    fn (string $href): string => $this->newAddress($tenant, $href, $known) ?? $href);
                $body = $this->withoutRepeatedTitle($page['title'], $body);
                $title = Str::limit($page['title'], 180, '');
                if (mb_strlen($title) < 5 || mb_strlen($body) < 50 || mb_strlen($body) > 60000) {
                    throw new \RuntimeException('Title or text is too short or too long for the blog.');
                }
                $cover = ! $dryRun && $firstImage ? $this->storeImage($firstImage, 'public-posts/'.$tenant->id.'/'.Str::random(32).'.jpg', 1800, $source, $encoder) : null;
                if (! $dryRun) {
                    DB::table('public_posts')->insert([
                        'tenant_id' => $tenant->id, 'slug' => $slug, 'title' => $title, 'excerpt' => null, 'body' => $body, 'cover_path' => $cover,
                        'status' => $this->option('publish') ? 'published' : 'draft', 'published_at' => $this->publishedAt($page['date']) ?? now(),
                        'audience' => $audience, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $rows[$slug] = [$slug, ($dryRun ? 'Ready to import' : ($this->option('publish') ? 'Imported and published' : 'Imported as draft')).' (for '.$audience.')'];
            } catch (\Throwable $exception) {
                Storage::disk('local')->delete($this->newFiles);
                $failed = true;
                $rows[$slug] = [$slug, 'Failed: '.Str::limit($exception->getMessage(), 80)];
            }
        }
        $ordered = [];
        foreach (array_keys(self::SERVICE_PAGES) as $slug) {
            if (isset($rows[$slug])) {
                $ordered[] = $rows[$slug];
            }
        }
        $this->table(['Service page', 'Result'], $ordered);

        return $failed;
    }

    /** Point links to the old website at the matching article here, for every article that has one. */
    private function relinkArticles(Tenant $tenant, bool $dryRun): void
    {
        $slugs = array_fill_keys(DB::table('public_posts')->where('tenant_id', $tenant->id)->pluck('slug')->all(), true) + $this->dryRunPages;
        $changedPosts = 0;
        $changedLinks = 0;
        foreach (DB::table('public_posts')->where('tenant_id', $tenant->id)->get(['id', 'body']) as $post) {
            $count = 0;
            $body = preg_replace_callback('#\]\((https?://(?:www\.)?havensuitesbnb\.com/([a-z0-9]+(?:-[a-z0-9]+)*)/?)\)#i',
                function (array $match) use ($tenant, $slugs, &$count): string {
                    $target = $this->pageAddress($tenant, strtolower($match[2]), $slugs);
                    if ($target === null) {
                        return $match[0];
                    }
                    $count++;

                    return ']('.$target.')';
                }, (string) $post->body) ?? (string) $post->body;
            if ($count > 0) {
                $changedPosts++;
                $changedLinks += $count;
                if (! $dryRun) {
                    DB::table('public_posts')->where('id', $post->id)->update(['body' => $body]);
                }
            }
        }
        $this->info(sprintf('%s %d links to the old website in %d articles to their new pages.', $dryRun ? 'Would point' : 'Pointed', $changedLinks, $changedPosts));
    }

    /** Fill empty About page sections from the old About page: introduction, why choose us and the team. */
    private function importAbout(Tenant $tenant, WebsiteBlogSource $source, BlogHtmlToMarkdown $converter, PublicImageEncoder $encoder, bool $dryRun): void
    {
        $site = DB::table('public_sites')->where('tenant_id', $tenant->id)->first();
        if (! $site) {
            $this->warn('About page: set up the website in Website settings first.');

            return;
        }
        $page = $source->page(self::ABOUT_PAGE);
        if (! $page) {
            $this->warn('About page: not found on the old website.');

            return;
        }
        $sections = $this->sections($converter->convert($page['html'], fn (string $src): string => $src, fn (string $href): string => $href));
        $changes = [];
        $notes = [];
        $intro = $this->plain($sections['experience the epitome of comfort and convenience'] ?? []);
        if (trim((string) $site->about_intro) === '' && $intro !== []) {
            $changes['about_intro'] = implode("\n\n", $intro);
            $notes[] = count($intro).' introduction paragraphs';
        }
        $why = $this->reasons($sections['why choose haven suites?'] ?? []);
        if (trim((string) ($site->about_why ?? '')) === '' && $why !== []) {
            $changes['about_why'] = implode("\n", $why);
            $notes[] = count($why).' reasons to choose you';
        }
        $team = $this->team($sections['meet our team'] ?? []);
        $newFiles = [];
        if (PublicAboutTeam::saved($site) === [] && $team !== []) {
            foreach ($team as $index => $member) {
                $team[$index]['photo'] = ! $dryRun && $member['photo'] ? $this->teamPhoto($tenant, $member['photo'], $source, $encoder) : null;
                if ($team[$index]['photo']) {
                    $newFiles[] = $team[$index]['photo'];
                }
            }
            $changes['about_team'] = json_encode(array_slice($team, 0, PublicAboutTeam::MAX_MEMBERS), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $notes[] = count($team).' team members ('.implode(', ', array_column($team, 'name')).')';
        }
        if ($changes === []) {
            $this->line('About page: nothing to fill; its sections already have text. Clear a section in Website settings to import it again.');

            return;
        }
        if (! $dryRun) {
            try {
                DB::table('public_sites')->where('id', $site->id)->update($changes + ['version' => (int) $site->version + 1, 'updated_at' => now()]);
            } catch (\Throwable $exception) {
                Storage::disk('local')->delete($newFiles);
                throw $exception;
            }
        }
        $this->info(($dryRun ? 'About page: would fill ' : 'About page: filled ').implode(', ', $notes).'.');
    }

    /** @param array<string, true> $known */
    private function newAddress(Tenant $tenant, string $href, array $known): ?string
    {
        return app(WebsiteBlogSource::class)->isOwnSite($href) ? $this->pageAddress($tenant, trim((string) parse_url($href, PHP_URL_PATH), '/'), $known) : null;
    }

    /**
     * Where an old website page lives now: an article with the same web address, or the matching page of the new site.
     *
     * @param  array<string, true>  $articles
     */
    private function pageAddress(Tenant $tenant, string $slug, array $articles): ?string
    {
        if (isset($articles[$slug])) {
            return route('public-stays.blog.show', ['company' => $tenant->slug, 'slug' => $slug]);
        }

        return match ($slug) {
            'about-haven-suites', 'about-haven-suites-bnb' => route('public-stays.about', $tenant->slug),
            'contact' => route('public-stays.index', $tenant->slug).'#stay-contact',
            'travel-tips', 'haven-suites-blog' => route('public-stays.blog', $tenant->slug),
            'haven-suites-bnb-accommodations-2', 'haven-suites-bnb-accommodations' => route('public-stays.index', $tenant->slug).'#find-stay',
            'become-a-host' => route('public-stays.owners', $tenant->slug),
            default => null,
        };
    }

    /** Elementor pages repeat the page title as their first heading; the article page already shows the title. */
    private function withoutRepeatedTitle(string $title, string $body): string
    {
        if (preg_match('/^#{2,4} (.+)\n+/u', $body, $match)) {
            $heading = Str::lower(trim($match[1]));
            if ($heading !== '' && (str_contains(Str::lower($title), $heading) || str_contains($heading, Str::lower($title)))) {
                return ltrim(substr($body, strlen($match[0])));
            }
        }

        return $body;
    }

    /** @return array<string, list<string>> Markdown blocks grouped under each heading (lower case). */
    private function sections(string $markdown): array
    {
        $sections = [];
        $current = '';
        foreach (preg_split('/\n{2,}/', $markdown) ?: [] as $block) {
            if (preg_match('/^#{2,3} (.+)$/u', trim($block), $match) && ! str_starts_with(trim($block), '### ')) {
                $current = Str::lower(trim($match[1]));

                continue;
            }
            $sections[$current][] = trim($block);
        }

        return $sections;
    }

    /** @param list<string> $blocks @return list<string> Plain-text paragraphs, without images or links. */
    private function plain(array $blocks): array
    {
        return array_values(array_filter(array_map(fn (string $block): string => $this->text($block),
            array_filter($blocks, fn (string $block): bool => ! str_starts_with($block, '![') && ! str_starts_with($block, '#')))));
    }

    /** @param list<string> $blocks @return list<string> "Title: explanation" lines from "### Title:" headings and the text under them. */
    private function reasons(array $blocks): array
    {
        $reasons = [];
        $title = null;
        foreach ($blocks as $block) {
            if (str_starts_with($block, '### ')) {
                $title = rtrim($this->text(substr($block, 4)), ': ');
            } elseif ($title !== null && ! str_starts_with($block, '![') && ($text = $this->text($block)) !== '') {
                $reasons[] = $title.': '.$text;
                $title = null;
            }
        }

        return array_slice($reasons, 0, 8);
    }

    /**
     * Team cards on the old page: a photo, the name in bold, the role, the name and role repeated, then a short bio.
     *
     * @param  list<string>  $blocks
     * @return list<array{name: string, role: string, bio: string, photo: ?string}>
     */
    private function team(array $blocks): array
    {
        $team = [];
        $member = null;
        foreach ($blocks as $block) {
            if (preg_match('/^!\[[^\]]*\]\(([^)\s]+)\)$/', $block, $image)) {
                if ($member && $member['name'] !== '') {
                    $team[] = $member;
                }
                $member = ['name' => '', 'role' => '', 'bio' => '', 'photo' => $image[1]];

                continue;
            }
            if (! $member) {
                continue;
            }
            $text = $this->text($block);
            if (preg_match('/^\*\*(.+)\*\*$/u', $block, $bold)) {
                $member['name'] = $member['name'] ?: Str::title(Str::lower($this->text($bold[1])));
            } elseif ($member['role'] === '' && mb_strlen($text) <= 60) {
                $member['role'] = $text;
            } elseif (mb_strlen($text) > 60 && $member['bio'] === '') {
                $member['bio'] = Str::limit($text, 600, '…');
            }
        }
        if ($member && $member['name'] !== '') {
            $team[] = $member;
        }

        return $team;
    }

    private function teamPhoto(Tenant $tenant, string $url, WebsiteBlogSource $source, PublicImageEncoder $encoder): ?string
    {
        $bytes = $source->image($url);
        if ($bytes === null) {
            return null;
        }
        $temporary = tempnam(sys_get_temp_dir(), 'haven_team_');
        try {
            file_put_contents($temporary, $bytes);

            return app(PublicAboutTeam::class)->storePhoto($tenant, new UploadedFile($temporary, basename((string) parse_url($url, PHP_URL_PATH)), null, null, true), 'photo');
        } catch (ValidationException) {
            return null;
        } finally {
            @unlink($temporary);
        }
    }

    /** Markdown to plain text: links keep their words, formatting marks and escapes are removed. */
    private function text(string $markdown): string
    {
        $text = preg_replace(['/!\[[^\]]*\]\([^)]*\)/', '/\[([^\]]*)\]\([^)]*\)/', '/\\\\([\\\\`*_\[\]<>#])/', '/\*\*|__/'], ['', '$1', '$1', ''], $markdown) ?? $markdown;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
