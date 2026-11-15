<?php

namespace App\Services;

use App\Models\Tenant;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Search checklist for a blog post, in the spirit of WordPress SEO plugins: length, focus keyword placement, search
 * title and description, sections, links to and from other pages, photos and readability. Each check is "good",
 * "ok" or "fix", and the score is the share of checks passed (an "ok" counts half).
 */
class BlogSeoCheck
{
    public const GOOD_WORDS = 1200;

    public const MIN_WORDS = 600;

    public function __construct(private PublicBlog $blog) {}

    /**
     * @param  array{title?: ?string, slug?: ?string, body?: ?string, excerpt?: ?string, seo_title?: ?string, seo_description?: ?string, focus_keyword?: ?string, has_cover?: bool, id?: ?int}  $post
     * @param  array<string, list<string>>|null  $inbound  titles of posts linking to each slug, from inboundLinks(); looked up when null
     * @return array{score: int, grade: string, checks: list<array{status: string, label: string, detail: string}>, stats: array<string, mixed>, snippet: array{title: string, url: string, description: string}}
     */
    public function analyse(Tenant $tenant, array $post, ?array $inbound = null): array
    {
        $title = trim((string) ($post['title'] ?? ''));
        $slug = trim((string) ($post['slug'] ?? '')) ?: Str::slug($title);
        $keyword = trim(preg_replace('/\s+/u', ' ', (string) ($post['focus_keyword'] ?? '')) ?? '');
        $html = $this->blog->html((string) ($post['body'] ?? ''));
        $content = $this->read($tenant, $html);
        $words = $content['words'];
        $searchTitle = trim((string) ($post['seo_title'] ?? '')) ?: ($title !== '' ? $title.' | '.$tenant->name : '');
        $autoDescription = trim((string) ($post['excerpt'] ?? '')) ?: Str::limit($content['text'], 180, '…');
        $description = trim((string) ($post['seo_description'] ?? '')) ?: $autoDescription;
        $inbound ??= $this->inboundLinks($tenant);
        $linkedFrom = array_values(array_filter($inbound[$slug] ?? [], fn (string $from): bool => $from !== $title));
        $checks = [];

        $checks[] = $words >= self::GOOD_WORDS ? $this->good('Length', number_format($words).' words. Long, complete guides compete best.')
            : ($words >= self::MIN_WORDS ? $this->ok('Length', number_format($words).' words. Aim for '.number_format(self::GOOD_WORDS).'+ to compete for popular searches.')
                : $this->fix('Length', number_format($words).' words. Write at least '.self::MIN_WORDS.'; '.number_format(self::GOOD_WORDS).'+ is better.'));

        if ($keyword === '') {
            $checks[] = $this->fix('Focus keyword', 'Add the phrase guests would type into Google, e.g. "serviced apartments in Kilimani".');
        } else {
            $phrase = mb_strtolower($keyword);
            $count = $this->occurrences($content['text'], $phrase);
            $density = $words > 0 ? $count * max(1, count(explode(' ', $phrase))) / $words * 100 : 0;
            $checks[] = str_contains(mb_strtolower($title), $phrase)
                ? (mb_stripos($title, $keyword) <= 10 ? $this->good('Keyword in title', 'The title starts with the focus keyword.') : $this->ok('Keyword in title', 'In the title. Moving it nearer the start helps a little.'))
                : $this->fix('Keyword in title', 'Use "'.$keyword.'" in the title.');
            $checks[] = str_contains(mb_strtolower($searchTitle), $phrase) ? $this->good('Keyword in search title', 'Shown in the Google result title.') : $this->fix('Keyword in search title', 'Put the keyword in the search title.');
            $checks[] = str_contains(mb_strtolower($description), $phrase) ? $this->good('Keyword in description', 'Google shows it in bold under the title.') : $this->fix('Keyword in description', 'Use the keyword in the search description.');
            $checks[] = str_contains($slug, Str::slug($keyword)) ? $this->good('Keyword in web address', '/blog/'.$slug) : $this->ok('Keyword in web address', 'A web address containing "'.Str::slug($keyword).'" helps. Change it only before publishing: old links would break.');
            $checks[] = $this->occurrences($content['intro'], $phrase) > 0 ? $this->good('Keyword at the start', 'Used in the first paragraph.') : $this->fix('Keyword at the start', 'Mention the keyword in the first paragraph.');
            $checks[] = collect($content['headings'])->contains(fn (string $heading): bool => str_contains(mb_strtolower($heading), $phrase))
                ? $this->good('Keyword in a section heading', 'At least one heading uses it.') : $this->ok('Keyword in a section heading', 'Use the keyword in one section heading.');
            $checks[] = $density >= 0.5 && $density <= 2.5 ? $this->good('Keyword use', $count.' times ('.number_format($density, 1).'%). Natural.')
                : ($density > 3 ? $this->fix('Keyword use', $count.' times ('.number_format($density, 1).'%). Too often: Google may see it as stuffing.')
                    : $this->ok('Keyword use', $count.' times ('.number_format($density, 1).'%). Aim for 0.5% to 2.5%: about '.max(1, (int) ceil($words / 200)).' mentions.'));
            $others = DB::table('public_posts')->where('tenant_id', $tenant->id)->where('id', '!=', (int) ($post['id'] ?? 0))
                ->whereRaw('LOWER(focus_keyword) = ?', [$phrase])->pluck('title');
            if ($others->isNotEmpty()) {
                $checks[] = $this->ok('Keyword not used elsewhere', 'Also the focus keyword of "'.$others->first().'". Two posts competing for one search weaken both.');
            }
        }

        $titleLength = mb_strlen($searchTitle);
        $checks[] = $titleLength >= 30 && $titleLength <= 60 ? $this->good('Search title length', $titleLength.' characters.')
            : $this->ok('Search title length', $titleLength.' characters. 30 to 60 shows in full on Google.');
        $descriptionLength = mb_strlen($description);
        $usingAuto = trim((string) ($post['seo_description'] ?? '')) === '';
        $checks[] = $descriptionLength >= 120 && $descriptionLength <= 160 && ! $usingAuto ? $this->good('Search description', $descriptionLength.' characters.')
            : ($usingAuto ? $this->ok('Search description', 'Using the first lines of the post. Write a description of 120 to 160 characters that makes people click.')
                : $this->ok('Search description', $descriptionLength.' characters. 120 to 160 shows in full.'));

        $sections = count($content['h2']);
        $checks[] = $sections >= 3 ? $this->good('Sections', $sections.' section headings. Readers can scan and a table of contents is shown.')
            : ($sections > 0 ? $this->ok('Sections', $sections.' section heading'.($sections === 1 ? '' : 's').'. Use at least 3 for a long guide.') : $this->fix('Sections', 'Break the post into sections with headings.'));
        $internal = count($content['internal']);
        $checks[] = $internal >= 3 ? $this->good('Links to your pages', $internal.' links to your stays, area pages or other guides.')
            : ($internal > 0 ? $this->ok('Links to your pages', $internal.' link'.($internal === 1 ? '' : 's').'. Add 3+ links to related guides, areas or stays.') : $this->fix('Links to your pages', 'Link to related guides, area pages or stays on your website.'));
        $checks[] = count($content['external']) > 0 ? $this->good('Links to sources', count($content['external']).' link'.(count($content['external']) === 1 ? '' : 's').' to other websites.')
            : $this->ok('Links to sources', 'Link to a trusted source (e.g. KRA, county or tourism sites) to support facts.');
        if ($content['legacy'] !== []) {
            $checks[] = $this->ok('Links to the old website', count($content['legacy']).' link'.(count($content['legacy']) === 1 ? ' goes' : 's go').' to '.WebsiteBlogSource::HOSTS[0]
                .'. Point '.(count($content['legacy']) === 1 ? 'it' : 'them').' to the matching page here before the old site is switched off.');
        }
        $from = count($linkedFrom);
        $checks[] = $from >= 2 ? $this->good('Linked from other posts', $from.' posts link here.')
            : ($from === 1 ? $this->ok('Linked from other posts', '1 post links here. Link to it from 2+ related guides.') : $this->fix('Linked from other posts', 'No other post links here, so readers and Google struggle to find it.'));
        $checks[] = $content['images'] > 0 ? ($content['missing_alt'] === 0 ? $this->good('Photos', $content['images'].' photo'.($content['images'] === 1 ? '' : 's').' with descriptions.')
            : $this->ok('Photos', $content['missing_alt'].' of '.$content['images'].' photos have no description (alt text).')) : $this->fix('Photos', 'Add at least one photo to the text.');
        $checks[] = ! empty($post['has_cover']) ? $this->good('Cover photo', 'Shown on Google, WhatsApp and Facebook shares.') : $this->fix('Cover photo', 'Add a cover photo; shares without one get few clicks.');
        $checks[] = $content['sentence_words'] <= 20 ? $this->good('Easy to read', 'Sentences average '.$content['sentence_words'].' words.')
            : $this->ok('Easy to read', 'Sentences average '.$content['sentence_words'].' words. Shorter sentences (under 20) read better on phones.');
        if ($content['long_paragraphs'] > 0) {
            $checks[] = $this->ok('Short paragraphs', $content['long_paragraphs'].' paragraph'.($content['long_paragraphs'] === 1 ? ' is' : 's are').' over 150 words. Split long paragraphs.');
        }

        $points = array_sum(array_map(fn (array $check): float => ['good' => 1, 'ok' => 0.5, 'fix' => 0][$check['status']], $checks));
        $score = (int) round($points / max(1, count($checks)) * 100);

        return [
            'score' => $score, 'grade' => $score >= 80 ? 'good' : ($score >= 55 ? 'ok' : 'fix'), 'checks' => $checks,
            'stats' => ['words' => $words, 'reading_minutes' => max(1, (int) ceil($words / 200)), 'sections' => $sections, 'internal_links' => $internal,
                'external_links' => count($content['external']), 'linked_from' => $linkedFrom, 'images' => $content['images']],
            'snippet' => ['title' => Str::limit($searchTitle, 60, '…'), 'url' => preg_replace('#^https?://#', '', rtrim(config('app.url'), '/')).' › stays › blog › '.$slug,
                'description' => Str::limit($description, 160, '…')],
        ];
    }

    /** @return array<string, list<string>> For each post web address, the titles of other posts that link to it. */
    public function inboundLinks(Tenant $tenant): array
    {
        $links = [];
        foreach (DB::table('public_posts')->where('tenant_id', $tenant->id)->get(['title', 'body']) as $post) {
            preg_match_all('#/stays/'.preg_quote($tenant->slug, '#').'/blog/([a-z0-9]+(?:-[a-z0-9]+)*)(?![a-z0-9/-])#', (string) $post->body, $matches);
            foreach (array_unique($matches[1]) as $slug) {
                $links[$slug][] = $post->title;
            }
        }

        return $links;
    }

    public static function countWords(string $text): int
    {
        return count(preg_split('/[^\p{L}\p{N}\'’-]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * @return array{text: string, intro: string, words: int, headings: list<string>, h2: list<string>, internal: list<string>, external: list<string>, legacy: list<string>, images: int, missing_alt: int, sentence_words: int, long_paragraphs: int}
     */
    private function read(Tenant $tenant, string $html): array
    {
        $document = new DOMDocument;
        @$document->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><body>'.$html.'</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);
        $text = trim(preg_replace('/\s+/u', ' ', $document->textContent) ?? '');
        $paragraphs = [];
        foreach ($xpath->query('//p') ?: [] as $paragraph) {
            $paragraphs[] = trim(preg_replace('/\s+/u', ' ', $paragraph->textContent) ?? '');
        }
        $headings = [];
        $h2 = [];
        foreach ($xpath->query('//h2|//h3') ?: [] as $heading) {
            $headings[] = trim($heading->textContent);
            if ($heading->nodeName === 'h2') {
                $h2[] = trim($heading->textContent);
            }
        }
        $own = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $internal = [];
        $external = [];
        $legacy = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            $host = strtolower((string) parse_url($href, PHP_URL_HOST));
            if ($host === '' && str_starts_with($href, '/')) {
                $internal[] = $href;
            } elseif (in_array($host, WebsiteBlogSource::HOSTS, true)) {
                $legacy[] = $href;
            } elseif ($host === $own && str_contains((string) parse_url($href, PHP_URL_PATH), '/stays/'.$tenant->slug)) {
                $internal[] = $href;
            } elseif (preg_match('#^https?://#i', $href)) {
                $external[] = $href;
            }
        }
        $images = $xpath->query('//img');
        $missingAlt = 0;
        foreach ($images ?: [] as $image) {
            $missingAlt += trim($image->getAttribute('alt')) === '' ? 1 : 0;
        }
        $words = self::countWords($text);
        $sentences = max(1, count(preg_split('/[.!?]+(?:\s|$)/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []));
        $intro = implode(' ', array_slice($paragraphs, 0, 1));

        return [
            'text' => $text, 'intro' => $intro, 'words' => $words, 'headings' => $headings, 'h2' => $h2,
            'internal' => array_values(array_unique($internal)), 'external' => array_values(array_unique($external)), 'legacy' => array_values(array_unique($legacy)),
            'images' => $images ? $images->length : 0, 'missing_alt' => $missingAlt,
            'sentence_words' => (int) round($words / $sentences),
            'long_paragraphs' => count(array_filter($paragraphs, fn (string $paragraph): bool => self::countWords($paragraph) > 150)),
        ];
    }

    private function occurrences(string $text, string $phrase): int
    {
        return $phrase === '' ? 0 : preg_match_all('/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/iu', $text);
    }

    /** @return array{status: string, label: string, detail: string} */
    private function good(string $label, string $detail): array
    {
        return ['status' => 'good', 'label' => $label, 'detail' => $detail];
    }

    /** @return array{status: string, label: string, detail: string} */
    private function ok(string $label, string $detail): array
    {
        return ['status' => 'ok', 'label' => $label, 'detail' => $detail];
    }

    /** @return array{status: string, label: string, detail: string} */
    private function fix(string $label, string $detail): array
    {
        return ['status' => 'fix', 'label' => $label, 'detail' => $detail];
    }
}
