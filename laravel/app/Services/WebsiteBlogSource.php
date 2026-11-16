<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Reads published blog posts and their images from the company's existing WordPress website. */
class WebsiteBlogSource
{
    public const ORIGIN = 'https://havensuitesbnb.com';

    public const HOSTS = ['havensuitesbnb.com', 'www.havensuitesbnb.com'];

    private const MAX_IMAGE_BYTES = 10 * 1024 * 1024;

    /**
     * Every published post, oldest first.
     *
     * @return list<array{id: int, slug: string, title: string, excerpt: string, html: string, date: string, featured_media: int}>
     */
    public function posts(): array
    {
        $posts = [];
        for ($page = 1; $page <= 20; $page++) {
            $response = Http::acceptJson()->connectTimeout(10)->timeout(40)->withOptions(['allow_redirects' => false])->retry(2, 300)
                ->get(self::ORIGIN.'/wp-json/wp/v2/posts', ['per_page' => 50, 'page' => $page, 'status' => 'publish', 'orderby' => 'date', 'order' => 'asc',
                    '_fields' => 'id,slug,date,title,excerpt,content,featured_media']);
            if (! $response->successful() || strlen($response->body()) > 20 * 1024 * 1024) {
                throw new RuntimeException('The website blog could not be read completely. Nothing was imported.');
            }
            $rows = $response->json();
            if (! is_array($rows) || ! array_is_list($rows)) {
                throw new RuntimeException('The website returned an unexpected blog format. Nothing was imported.');
            }
            foreach ($rows as $row) {
                $slug = (string) ($row['slug'] ?? '');
                if (! is_int($row['id'] ?? null) || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) || isset($posts[$row['id']])) {
                    throw new RuntimeException('A website post has an unexpected ID or web address. Nothing was imported.');
                }
                $posts[$row['id']] = [
                    'id' => $row['id'], 'slug' => $slug,
                    'title' => $this->text((string) ($row['title']['rendered'] ?? '')),
                    'excerpt' => trim(preg_replace('/\s*(\[(&hellip;|…|\.\.\.)\]|\[…\])\s*$/u', '', $this->text((string) ($row['excerpt']['rendered'] ?? ''))) ?? ''),
                    'html' => (string) ($row['content']['rendered'] ?? ''), 'date' => (string) ($row['date'] ?? ''),
                    'featured_media' => (int) ($row['featured_media'] ?? 0),
                ];
            }
            $totalPages = (int) $response->header('X-WP-TotalPages');
            if (count($rows) < 50 || ($totalPages > 0 && $page >= $totalPages)) {
                $total = $response->header('X-WP-Total');
                if ($total !== '' && $total !== null && count($posts) !== (int) $total) {
                    throw new RuntimeException('The website blog changed while it was being read. Run the import again.');
                }

                return array_values($posts);
            }
        }

        throw new RuntimeException('The website blog has more posts than this import supports. Nothing was imported.');
    }

    /**
     * One published page by its web address, or null when the website does not have it.
     *
     * @return array{slug: string, title: string, html: string, date: string, featured_media: int}|null
     */
    public function page(string $slug): ?array
    {
        $response = Http::acceptJson()->connectTimeout(10)->timeout(40)->withOptions(['allow_redirects' => false])->retry(2, 300)
            ->get(self::ORIGIN.'/wp-json/wp/v2/pages', ['slug' => $slug, 'status' => 'publish', '_fields' => 'slug,date,title,content,featured_media']);
        $row = $response->successful() ? ($response->json()[0] ?? null) : null;
        if (! is_array($row) || ($row['slug'] ?? null) !== $slug) {
            return null;
        }

        return ['slug' => $slug, 'title' => $this->text((string) ($row['title']['rendered'] ?? '')), 'html' => (string) ($row['content']['rendered'] ?? ''),
            'date' => (string) ($row['date'] ?? ''), 'featured_media' => (int) ($row['featured_media'] ?? 0)];
    }

    /**
     * Full-size addresses of featured images, keyed by media ID.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    public function mediaUrls(array $ids): array
    {
        $urls = [];
        foreach (array_chunk(array_values(array_unique(array_filter($ids))), 50) as $chunk) {
            $response = Http::acceptJson()->connectTimeout(10)->timeout(30)->withOptions(['allow_redirects' => false])->retry(2, 300)
                ->get(self::ORIGIN.'/wp-json/wp/v2/media', ['include' => implode(',', $chunk), 'per_page' => 50, '_fields' => 'id,source_url']);
            foreach ($response->successful() && is_array($response->json()) ? $response->json() : [] as $media) {
                if (is_int($media['id'] ?? null) && is_string($media['source_url'] ?? null)) {
                    $urls[$media['id']] = $media['source_url'];
                }
            }
        }

        return $urls;
    }

    public function isOwnSite(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && in_array(strtolower((string) ($parts['host'] ?? '')), self::HOSTS, true) && ! isset($parts['user']) && ! isset($parts['port']);
    }

    /** Image bytes from the website's own uploads folder, or null when the image is elsewhere, missing or too large. */
    public function image(string $url): ?string
    {
        if (! $this->isOwnSite($url) || ! str_starts_with((string) parse_url($url, PHP_URL_PATH), '/wp-content/uploads/')) {
            return null;
        }
        try {
            $response = Http::connectTimeout(10)->timeout(60)->withOptions(['allow_redirects' => false])->retry(2, 300, throw: false)
                ->get('https://'.parse_url($url, PHP_URL_HOST).parse_url($url, PHP_URL_PATH));
        } catch (\Throwable) {
            return null;
        }
        $body = $response->body();

        return $response->successful() && str_starts_with(strtolower($response->header('Content-Type')), 'image/')
            && $body !== '' && strlen($body) <= self::MAX_IMAGE_BYTES ? $body : null;
    }

    private function text(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }
}
