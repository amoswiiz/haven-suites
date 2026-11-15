<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\BlogHtmlToMarkdown;
use App\Services\ListingPhotoVariants;
use App\Services\PublicImageEncoder;
use App\Services\WebsiteBlogSource;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

#[Signature('haven:import-website-blog {tenant : Company slug} {--actor= : User ID shown as the author (optional)} {--publish : Publish the posts with their original dates instead of saving drafts} {--dry-run : Read and convert the posts without saving anything or downloading images}')]
#[Description('Copy the blog posts from the WordPress website into the company blog, with their images. Existing posts are never changed.')]
class ImportWebsiteBlog extends Command
{
    /** @var array<string, ?string> Image already stored for a website address, so a photo used twice is saved once. */
    private array $storedImages = [];

    /** @var list<string> Files saved for the post being imported, removed again if that post fails. */
    private array $newFiles = [];

    /** @var list<string> Website image addresses first stored while importing the current post. */
    private array $newImageSources = [];

    public function handle(WebsiteBlogSource $source, BlogHtmlToMarkdown $converter, PublicImageEncoder $encoder): int
    {
        $tenant = Tenant::where('slug', $this->argument('tenant'))->where('active', true)->first();
        if (! $tenant) {
            $this->error('Choose an active company by its slug, for example: haven');

            return self::FAILURE;
        }
        $actor = $this->option('actor');
        if ($actor !== null && (! ctype_digit((string) $actor) || ! DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', (int) $actor)->exists())) {
            $this->error('--actor must be the ID of a user who belongs to this company.');

            return self::FAILURE;
        }
        $dryRun = (bool) $this->option('dry-run');
        $publish = (bool) $this->option('publish');

        try {
            $posts = $source->posts();
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $existing = array_fill_keys(DB::table('public_posts')->where('tenant_id', $tenant->id)->pluck('slug')->all(), true);
        $newAddresses = [];
        foreach ($posts as $post) {
            $newAddresses[$post['slug']] = route('public-stays.blog.show', ['company' => $tenant->slug, 'slug' => $post['slug']]);
        }
        $covers = $dryRun ? [] : $source->mediaUrls(array_column($posts, 'featured_media'));
        $rows = [];
        $counts = ['imported' => 0, 'skipped' => 0, 'failed' => 0, 'images' => 0, 'images_skipped' => 0];

        foreach ($posts as $post) {
            if (isset($existing[$post['slug']])) {
                $rows[] = [$post['slug'], 'Skipped: already in the blog', '', ''];
                $counts['skipped']++;

                continue;
            }
            $this->newFiles = [];
            $this->newImageSources = [];
            $images = 0;
            $missingImages = 0;
            $links = 0;
            try {
                $body = $converter->convert($post['html'],
                    function (string $src) use ($dryRun, $tenant, $source, $encoder, &$images, &$missingImages): ?string {
                        $url = $dryRun ? ($source->isOwnSite($src) ? $src : null) : $this->storeInlineImage($tenant, $src, $source, $encoder);
                        $url ? $images++ : $missingImages++;

                        return $url;
                    },
                    function (string $href) use ($source, $newAddresses, &$links): ?string {
                        $path = trim((string) parse_url($href, PHP_URL_PATH), '/');
                        if ($source->isOwnSite($href) && isset($newAddresses[$path])) {
                            $links++;

                            return $newAddresses[$path];
                        }

                        return $href;
                    });
                $title = Str::limit($post['title'], 180, '');
                if (mb_strlen($title) < 5 || mb_strlen($body) < 50 || mb_strlen($body) > 60000) {
                    throw new \RuntimeException('Title or text is too short or too long for the blog.');
                }
                $cover = null;
                if (! $dryRun && isset($covers[$post['featured_media']])) {
                    $cover = $this->storeImage($covers[$post['featured_media']], 'public-posts/'.$tenant->id.'/'.Str::random(32).'.jpg', 1800, $source, $encoder);
                }
                $publishedAt = $this->publishedAt($post['date']);
                if (! $dryRun) {
                    DB::table('public_posts')->insert([
                        'tenant_id' => $tenant->id, 'slug' => $post['slug'], 'title' => $title,
                        'excerpt' => $post['excerpt'] !== '' ? Str::limit($post['excerpt'], 317) : null, 'body' => $body,
                        'cover_path' => $cover, 'status' => $publish ? 'published' : 'draft', 'published_at' => $publishedAt,
                        'author_id' => $actor !== null ? (int) $actor : null, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $existing[$post['slug']] = true;
                $counts['imported']++;
                $counts['images'] += $images + ($cover ? 1 : 0);
                $counts['images_skipped'] += $missingImages;
                $rows[] = [$post['slug'], $dryRun ? 'Ready to import' : ($publish ? 'Imported and published' : 'Imported as draft'), $images.($missingImages ? ' ('.$missingImages.' left out)' : ''), $links];
            } catch (\Throwable $exception) {
                Storage::disk('local')->delete($this->newFiles);
                foreach ($this->newImageSources as $src) {
                    unset($this->storedImages[$src]);
                }
                $counts['failed']++;
                $rows[] = [$post['slug'], 'Failed: '.Str::limit($exception->getMessage(), 80), '', ''];
            }
        }

        $this->table(['Post', 'Result', 'Images in text', 'Links to other posts'], $rows);
        $this->info(sprintf('%s %d of %d posts. %d already in the blog. %d failed. %d images saved, %d left out.',
            $dryRun ? 'Dry run: would import' : 'Imported', $counts['imported'], count($posts), $counts['skipped'], $counts['failed'], $counts['images'], $counts['images_skipped']));
        if ($dryRun) {
            $this->line('Nothing was saved. Run again without --dry-run to import.');
        } elseif (! $publish && $counts['imported'] > 0) {
            $this->line('Posts are saved as drafts. Review them in Public listings → Blog and publish, or run again with --publish for new posts.');
        }

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function storeInlineImage(Tenant $tenant, string $src, WebsiteBlogSource $source, PublicImageEncoder $encoder): ?string
    {
        if (array_key_exists($src, $this->storedImages)) {
            return $this->storedImages[$src];
        }
        $name = Str::random(32);
        $this->newImageSources[] = $src;
        $stored = $this->storeImage($src, 'public-posts/'.$tenant->id.'/inline/'.$name.'.jpg', 1600, $source, $encoder);

        return $this->storedImages[$src] = $stored ? route('public-stays.blog.image', [$tenant->slug, $name]) : null;
    }

    /** Download a website image, re-encode it as a JPEG without metadata and save it. Returns the storage path, or null. */
    private function storeImage(string $url, string $path, int $maxSide, WebsiteBlogSource $source, PublicImageEncoder $encoder): ?string
    {
        $bytes = $source->image($url);
        if ($bytes === null) {
            return null;
        }
        $temporary = tempnam(sys_get_temp_dir(), 'haven_blog_');
        try {
            file_put_contents($temporary, $bytes);
            $jpeg = $encoder->jpeg(new UploadedFile($temporary, basename((string) parse_url($url, PHP_URL_PATH)), null, null, true), 'image', $maxSide);
        } catch (ValidationException) {
            return null;
        } finally {
            @unlink($temporary);
        }
        Storage::disk('local')->put($path, $jpeg);
        $this->newFiles[] = $path;
        if (app(ListingPhotoVariants::class)->make($path) > 0) {
            foreach (ListingPhotoVariants::WIDTHS as $width) {
                $this->newFiles[] = ListingPhotoVariants::key($path, $width);
            }
        }

        return $path;
    }

    /** WordPress gives the post date in the website's local time, which is Nairobi time. */
    private function publishedAt(string $date): ?string
    {
        try {
            return CarbonImmutable::parse($date, 'Africa/Nairobi')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
