<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Blog posts for the public website. Posts are written in simple Markdown (headings, lists, bold and links);
 * raw HTML is stripped and unsafe links are removed when rendering.
 */
class PublicBlog
{
    /** Published posts, newest first. A post scheduled for later is not shown until its date. */
    public function published(Tenant $tenant, ?int $limit = null): Collection
    {
        return DB::table('public_posts')->where('tenant_id', $tenant->id)->where('status', 'published')
            ->whereNotNull('published_at')->where('published_at', '<=', now())
            ->orderByDesc('published_at')->orderByDesc('id')->when($limit, fn ($query) => $query->limit($limit))->get()
            ->map(fn (object $post): object => $this->present($tenant, $post));
    }

    public function hasPublished(Tenant $tenant): bool
    {
        return DB::table('public_posts')->where('tenant_id', $tenant->id)->where('status', 'published')
            ->whereNotNull('published_at')->where('published_at', '<=', now())->exists();
    }

    public function find(Tenant $tenant, string $slug): ?object
    {
        $post = DB::table('public_posts')->where('tenant_id', $tenant->id)->where('slug', $slug)->where('status', 'published')
            ->whereNotNull('published_at')->where('published_at', '<=', now())->first();

        return $post ? $this->present($tenant, $post) : null;
    }

    /**
     * Post text as HTML. Pictures load only when scrolled to, and pictures stored with the blog editor offer their
     * smaller WebP copies so phones download a fraction of the size.
     */
    public function html(string $body): string
    {
        $html = Str::markdown($body, ['html_input' => 'strip', 'allow_unsafe_links' => false, 'max_nesting_level' => 10]);

        return preg_replace_callback('#<img src="([^"]+)"([^>]*?)\s*/?>#', function (array $match): string {
            $src = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $sized = '';
            if (preg_match('#/blog/images/[A-Za-z0-9]{32}$#D', (string) parse_url($src, PHP_URL_PATH)) && parse_url($src, PHP_URL_QUERY) === null) {
                $sized = ' srcset="'.e(self::srcset($src)).'" sizes="(max-width: 800px) 100vw, 760px"';
                $src = self::sized($src, 1200);
            }

            return '<img src="'.e($src).'"'.$match[2].$sized.' loading="lazy" decoding="async">';
        }, $html) ?? $html;
    }

    /** A blog photo address asking for its smaller copy, e.g. "...?v=1&w=480". */
    public static function sized(string $url, int $width): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').'w='.$width;
    }

    /** "url?w=480 480w, url?w=1200 1200w" for an <img srcset>. */
    public static function srcset(string $url): string
    {
        return implode(', ', array_map(fn (int $width): string => self::sized($url, $width).' '.$width.'w', ListingPhotoVariants::WIDTHS));
    }

    public function present(Tenant $tenant, object $post): object
    {
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($this->html($post->body))) ?? '');
        $post->summary = $post->excerpt ?: Str::limit($plain, 180, '…');
        $post->reading_minutes = max(1, (int) ceil(str_word_count($plain) / 200));
        $post->cover_url = $post->cover_path ? route('public-stays.blog.cover', ['company' => $tenant->slug, 'slug' => $post->slug]).'?v='.strtotime((string) $post->updated_at) : null;

        return $post;
    }
}
