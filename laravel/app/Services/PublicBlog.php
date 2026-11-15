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
    /** Who a post is written for. Guest posts appear around the booking pages; owner posts on the owners page. */
    public const AUDIENCES = ['guests' => 'Guests and travellers', 'owners' => 'Property owners and investors'];

    /** Words that mark a post as written for property owners, hosts and investors rather than guests. */
    private const OWNER_WORDS = ['investor', 'investors', 'investment', 'invest', 'landlord', 'landlords', 'host', 'hosts', 'hosting', 'superhost',
        'property management', 'management company', 'letting agent', 'rental agent', 'yield', 'roi', 'cash flow', 'capital appreciation',
        'occupancy', 'revenue', 'income', 'profitable', 'profit', 'tax', 'taxes', 'vat', 'license', 'licence', 'regulation', 'regulations',
        'zoning', 'compliance', 'partnership', 'standard operating procedures', 'automate', 'inventory', 'asset management', 'pricing strategies',
        'for sale', 'forecast', 'listing', 'setup checklist', 'tenants', 'property owners', 'your airbnb', 'guests look for', 'guest experience system', 'airbnb management', 'perform well',
        'for corporate short-term rentals', 'for short-term rentals'];

    /** Published posts, newest first. A post scheduled for later is not shown until its date. */
    public function published(Tenant $tenant, ?int $limit = null, ?string $audience = null): Collection
    {
        return DB::table('public_posts')->where('tenant_id', $tenant->id)->where('status', 'published')
            ->whereNotNull('published_at')->where('published_at', '<=', now())
            ->when($audience, fn ($query) => $query->where('audience', $audience))
            ->orderByDesc('published_at')->orderByDesc('id')->when($limit, fn ($query) => $query->limit($limit))->get()
            ->map(fn (object $post): object => $this->present($tenant, $post));
    }

    /**
     * Best guess at who a post is for, from its title (which counts most) and text. Staff can change it in the editor.
     */
    public static function guessAudience(string $title, string $body = ''): string
    {
        $score = fn (string $text): int => collect(self::OWNER_WORDS)
            ->sum(fn (string $word): int => preg_match_all('/(?<![\p{L}])'.preg_quote($word, '/').'(?![\p{L}])/iu', $text));
        $titleHits = $score($title);
        $bodyHits = $score(Str::limit(strip_tags($body), 4000, ''));

        return $titleHits > 0 || $bodyHits >= 15 ? 'owners' : 'guests';
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

        $html = preg_replace_callback('#<img src="([^"]+)"([^>]*?)\s*/?>#', function (array $match): string {
            $src = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $sized = '';
            if (preg_match('#/blog/images/[A-Za-z0-9]{32}$#D', (string) parse_url($src, PHP_URL_PATH)) && parse_url($src, PHP_URL_QUERY) === null) {
                $sized = ' srcset="'.e(self::srcset($src)).'" sizes="(max-width: 800px) 100vw, 760px"';
                $src = self::sized($src, 1200);
            }

            return '<img src="'.e($src).'"'.$match[2].$sized.' loading="lazy" decoding="async">';
        }, $html) ?? $html;
        $used = [];

        // Section headings get an anchor so the table of contents and search results can link straight to them.
        return preg_replace_callback('#<h([23])>(.*?)</h\1>#s', function (array $match) use (&$used): string {
            $id = Str::slug(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: 'section';
            $unique = $id;
            for ($number = 2; isset($used[$unique]); $number++) {
                $unique = $id.'-'.$number;
            }
            $used[$unique] = true;

            return '<h'.$match[1].' id="'.$unique.'">'.$match[2].'</h'.$match[1].'>';
        }, $html) ?? $html;
    }

    /**
     * "In this guide" links to each main section, for posts with at least three sections.
     *
     * @return list<array{id: string, title: string}>
     */
    public function contents(string $html): array
    {
        preg_match_all('#<h2 id="([^"]+)">(.*?)</h2>#s', $html, $matches, PREG_SET_ORDER);
        $sections = array_map(fn (array $match): array => ['id' => $match[1], 'title' => trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))], $matches);

        return count($sections) >= 3 ? $sections : [];
    }

    /**
     * Other published posts on the same topic: most shared title and focus-keyword words first, then the newest.
     * Linking related guides together helps readers and search engines find more of the site.
     *
     * @return Collection<int, object>
     */
    public function related(Tenant $tenant, object $post, int $limit = 3): Collection
    {
        $topic = self::topicWords($post->title.' '.($post->focus_keyword ?? ''));

        return $this->published($tenant, null, $post->audience ?? null)->reject(fn (object $other): bool => $other->id === $post->id)
            ->map(function (object $other) use ($topic): object {
                $other->shared_words = count(array_intersect($topic, self::topicWords($other->title.' '.($other->focus_keyword ?? ''))));

                return $other;
            })
            ->sortByDesc(fn (object $other): string => sprintf('%03d|%s', $other->shared_words, $other->published_at))->take($limit)->values();
    }

    /** @return list<string> Meaningful lowercase words, without common words such as "the" or "in". */
    public static function topicWords(string $text): array
    {
        $stop = array_flip(['a', 'an', 'and', 'are', 'as', 'at', 'be', 'best', 'by', 'can', 'do', 'for', 'from', 'guide', 'how', 'in', 'is', 'it', 'of', 'on', 'or',
            'should', 'the', 'to', 'vs', 'what', 'which', 'why', 'with', 'you', 'your', 'complete', 'ultimate', 'smart', 'kenya']);
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($words, fn (string $word): bool => mb_strlen($word) > 2 && ! isset($stop[$word]))));
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
        $post->word_count = BlogSeoCheck::countWords($plain);
        $post->reading_minutes = max(1, (int) ceil($post->word_count / 200));
        $post->cover_url = $post->cover_path ? route('public-stays.blog.cover', ['company' => $tenant->slug, 'slug' => $post->slug]).'?v='.strtotime((string) $post->updated_at) : null;

        return $post;
    }
}
