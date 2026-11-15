<?php

namespace App\Services;

use Closure;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Turns WordPress article HTML into the simple Markdown the blog editor uses: headings, paragraphs, lists, bold,
 * italics, links and images. Layout wrappers, styles, scripts and embeds are dropped; their text is kept.
 */
class BlogHtmlToMarkdown
{
    private const BREAK = "\u{E000}";

    /** @var Closure(string, string): ?string */
    private Closure $image;

    /** @var Closure(string): ?string */
    private Closure $link;

    /**
     * @param  Closure(string $src, string $alt): ?string  $image  returns the address to show, or null to leave the image out
     * @param  Closure(string $href): ?string  $link  returns the address to link to, or null to keep only the text
     */
    public function convert(string $html, Closure $image, Closure $link): string
    {
        $this->image = $image;
        $this->link = $link;
        $document = new DOMDocument;
        $root = 'haven-import-'.bin2hex(random_bytes(6));
        @$document->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><div id="'.$root.'">'.$html.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $container = (new DOMXPath($document))->query('//div[@id="'.$root.'"]')->item(0);
        if (! $container) {
            return '';
        }
        $markdown = implode("\n\n", $this->blocks($container));

        return trim(preg_replace("/\n{3,}/", "\n\n", $markdown) ?? '');
    }

    /** @return list<string> */
    private function blocks(DOMNode $parent): array
    {
        $blocks = [];
        $inline = '';
        foreach ($parent->childNodes as $node) {
            $name = $node instanceof DOMElement ? strtolower($node->nodeName) : '#text';
            if (in_array($name, ['script', 'style', 'noscript', 'iframe', 'form', 'button', 'svg'], true)) {
                continue;
            }
            if (! $this->isBlock($node)) {
                $inline .= $this->inline($node);

                continue;
            }
            $this->flush($inline, $blocks);
            array_push($blocks, ...$this->block($node));
        }
        $this->flush($inline, $blocks);

        return $blocks;
    }

    /** @return list<string> */
    private function block(DOMElement $element): array
    {
        $name = strtolower($element->nodeName);
        if (preg_match('/^h([1-6])$/', $name, $level)) {
            $text = $this->line(str_replace(['**', '*'], '', $this->inlineChildren($element)));

            return $text === '' ? [] : [str_repeat('#', max(2, min(4, (int) $level[1]))).' '.$text];
        }

        return match ($name) {
            'p' => array_values(array_filter([$this->paragraph($this->inlineChildren($element))])),
            'ul', 'ol' => array_values(array_filter([$this->listBlock($element, 0)])),
            'figure' => $this->figure($element),
            'hr' => ['---'],
            'blockquote' => array_map(fn (string $block): string => '> '.str_replace("\n", "\n> ", $block), $this->blocks($element)),
            default => $this->blocks($element),
        };
    }

    private function listBlock(DOMElement $list, int $depth): string
    {
        $ordered = strtolower($list->nodeName) === 'ol';
        $lines = [];
        $number = 1;
        foreach ($list->childNodes as $item) {
            if (! $item instanceof DOMElement || strtolower($item->nodeName) !== 'li') {
                continue;
            }
            $text = '';
            $nested = [];
            foreach ($item->childNodes as $child) {
                $childName = $child instanceof DOMElement ? strtolower($child->nodeName) : '';
                if ($childName === 'ul' || $childName === 'ol') {
                    $nested[] = $this->listBlock($child, $depth + 1);
                } else {
                    $text .= ' '.$this->inline($child);
                }
            }
            $text = $this->line($text);
            if ($text === '' && $nested === []) {
                continue;
            }
            $lines[] = str_repeat('   ', $depth).($ordered ? ($number++).'. ' : '- ').$text;
            array_push($lines, ...array_filter($nested));
        }

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private function figure(DOMElement $figure): array
    {
        $blocks = [];
        foreach ($figure->getElementsByTagName('img') as $image) {
            $markdown = $this->imageMarkdown($image);
            if ($markdown !== '') {
                $blocks[] = $markdown;
            }
        }
        $caption = $figure->getElementsByTagName('figcaption')->item(0);
        if ($blocks !== [] && $caption) {
            $text = $this->line($this->inlineChildren($caption));
            if ($text !== '') {
                $blocks[] = '*'.trim(str_replace('*', '', $text)).'*';
            }
        }

        return $blocks;
    }

    private function inline(DOMNode $node): string
    {
        if (! $node instanceof DOMElement) {
            return $node->nodeType === XML_TEXT_NODE ? $this->escape(preg_replace('/\s+/u', ' ', $node->textContent) ?? '') : '';
        }
        $name = strtolower($node->nodeName);

        return match ($name) {
            'br' => self::BREAK,
            'strong', 'b' => $this->wrap($this->inlineChildren($node), '**'),
            'em', 'i' => $this->wrap($this->inlineChildren($node), '*'),
            'a' => $this->anchor($node),
            'img' => $this->imageMarkdown($node),
            'script', 'style', 'noscript', 'iframe', 'svg', 'button' => '',
            default => $this->inlineChildren($node),
        };
    }

    private function inlineChildren(DOMNode $node): string
    {
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= $this->inline($child);
        }

        return $text;
    }

    private function anchor(DOMElement $anchor): string
    {
        $text = $this->inlineChildren($anchor);
        $href = trim($anchor->getAttribute('href'));
        if (trim(str_replace(self::BREAK, '', $text)) === '') {
            return $text;
        }
        $target = preg_match('#^(https?://|mailto:|tel:)#i', $href) ? ($this->link)($href) : null;

        return $target ? '['.trim($text).']('.str_replace([' ', ')'], ['%20', '%29'], $target).')' : $text;
    }

    private function imageMarkdown(DOMElement $image): string
    {
        $src = trim($image->getAttribute('data-src') ?: $image->getAttribute('src'));
        if (! preg_match('#^https?://#i', $src)) {
            return '';
        }
        $alt = $this->line(str_replace(['[', ']'], '', $image->getAttribute('alt')));
        $url = ($this->image)($src, $alt);

        return $url ? '!['.$alt.']('.str_replace([' ', ')'], ['%20', '%29'], $url).')' : '';
    }

    private function wrap(string $text, string $marker): string
    {
        $core = trim(str_replace(self::BREAK, ' ', $text));
        if ($core === '') {
            return $text === '' ? '' : ' ';
        }
        $lead = preg_match('/^\s/u', $text) ? ' ' : '';
        $trail = preg_match('/\s$/u', $text) ? ' ' : '';

        return $lead.$marker.$core.$marker.$trail;
    }

    private function paragraph(string $text): string
    {
        $lines = array_map(fn (string $line): string => preg_replace('/^(#{1,6})(?=\s|$)/', '\\\\$1', $this->line($line)) ?? '', explode(self::BREAK, $text));

        return implode("\\\n", array_values(array_filter($lines, fn (string $line): bool => $line !== '')));
    }

    private function line(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace(self::BREAK, ' ', $text)) ?? '');
    }

    /** @param  list<string>  $blocks */
    private function flush(string &$inline, array &$blocks): void
    {
        $paragraph = $this->paragraph($inline);
        if ($paragraph !== '') {
            $blocks[] = $paragraph;
        }
        $inline = '';
    }

    private function isBlock(DOMNode $node): bool
    {
        return $node instanceof DOMElement && in_array(strtolower($node->nodeName), [
            'p', 'div', 'section', 'article', 'header', 'footer', 'aside', 'main', 'figure', 'ul', 'ol', 'hr', 'blockquote', 'table',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'center',
        ], true);
    }

    /** Escape characters that would otherwise turn ordinary text into Markdown formatting or HTML. */
    private function escape(string $text): string
    {
        return preg_replace('/([\\\\`*_\[\]<>])/', '\\\\$1', $text) ?? $text;
    }
}
