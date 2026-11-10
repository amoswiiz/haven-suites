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

    public function html(string $body): string
    {
        return Str::markdown($body, ['html_input' => 'strip', 'allow_unsafe_links' => false, 'max_nesting_level' => 10]);
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
