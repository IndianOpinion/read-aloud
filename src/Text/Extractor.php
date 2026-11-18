<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Text;

/**
 * Extracts the readable text of a post from its stored content.
 *
 * It never runs the content filter chain: other plugins add share boxes,
 * dates and counters there, which would change the hash on every request.
 */
final class Extractor
{
    public const SKIP_CLASS = 'read-aloud-skip';

    /** Block types dropped with their inner content by default. */
    public const DEFAULT_SKIP_BLOCKS = [
        'core/code',
        'core/preformatted',
        'core/html',
        'core/embed',
        'core/image',
        'core/gallery',
        'core/video',
        'core/audio',
        'core/file',
        'core/table',
        'core/buttons',
        'core/button',
        'core/social-links',
        'core/shortcode',
        'core/separator',
        'core/spacer',
        'core/footnotes',
        'read-aloud/player',
    ];

    /** Elements removed with their content in HTML. */
    private const DROP_TAGS = [
        'pre', 'code', 'script', 'style', 'figure', 'iframe', 'table', 'object', 'embed',
        'video', 'audio', 'svg', 'noscript', 'template', 'button', 'form', 'select',
        'textarea', 'figcaption', 'canvas', 'math', 'img', 'picture', 'map',
    ];

    /** Elements that start and end a paragraph. */
    private const BLOCK_TAGS = [
        'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'blockquote', 'div', 'section',
        'article', 'aside', 'header', 'footer', 'dd', 'dt', 'dl', 'ul', 'ol', 'tr', 'td',
        'th', 'caption', 'figcaption', 'address', 'hr', 'main', 'nav', 'details', 'summary',
    ];

    /**
     * @param list<string> $skipBlocks
     * @param list<string> $keepShortcodes
     * @param list<string> $registeredShortcodes
     */
    public function __construct(
        private readonly Normaliser $normaliser,
        private readonly array $skipBlocks = self::DEFAULT_SKIP_BLOCKS,
        private readonly array $keepShortcodes = [],
        private readonly bool $keepTables = false,
        private readonly bool $keepCaptions = false,
        private readonly array $registeredShortcodes = []
    ) {
    }

    /** The spoken text for a title and stored content, before filters. */
    public function extract(string $title, string $content): string
    {
        $paragraphs = [];
        $titleText = $this->normaliser->sentence(strip_tags($title));
        if ($titleText !== '') {
            $paragraphs[] = $titleText;
        }
        foreach ($this->paragraphs($content) as $paragraph) {
            $paragraphs[] = $paragraph;
        }

        return $this->normaliser->normalise($paragraphs);
    }

    /**
     * @return list<string>
     */
    public function paragraphs(string $content): array
    {
        if (str_contains($content, '<!-- wp:') && function_exists('parse_blocks')) {
            $html = $this->blocksToHtml(parse_blocks($content));
        } else {
            $html = $content;
        }
        $html = $this->stripShortcodes($html);

        return $this->htmlToParagraphs($html);
    }

    /**
     * @param array<int|string, array<string, mixed>> $blocks
     */
    private function blocksToHtml(array $blocks): string
    {
        $skip = $this->skipBlocks;
        if ($this->keepTables) {
            $skip = array_values(array_diff($skip, ['core/table']));
        }
        $out = '';
        foreach ($blocks as $block) {
            $name = isset($block['blockName']) && is_string($block['blockName']) ? $block['blockName'] : null;
            $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
            $className = isset($attrs['className']) && is_string($attrs['className']) ? $attrs['className'] : '';
            $innerHtml = isset($block['innerHTML']) && is_string($block['innerHTML']) ? $block['innerHTML'] : '';
            $dropped = $name !== null && (in_array($name, $skip, true) || str_starts_with($name, 'core-embed/'));
            if ($dropped || $this->hasSkipClass($className)) {
                if ($this->keepCaptions && !$this->hasSkipClass($className)) {
                    foreach ($this->captions($innerHtml) as $caption) {
                        $out .= '<p>' . htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') . '</p>';
                    }
                }
                continue;
            }
            $inner = isset($block['innerBlocks']) && is_array($block['innerBlocks']) ? $block['innerBlocks'] : [];
            $contentParts = isset($block['innerContent']) && is_array($block['innerContent']) ? $block['innerContent'] : [$innerHtml];
            $index = 0;
            $piece = '';
            foreach ($contentParts as $part) {
                if ($part === null) {
                    if (isset($inner[$index]) && is_array($inner[$index])) {
                        $piece .= $this->blocksToHtml([$inner[$index]]);
                    }
                    $index++;
                } elseif (is_string($part)) {
                    $piece .= $part;
                }
            }
            $out .= $name === null ? $piece : '<div>' . $piece . '</div>';
        }

        return $out;
    }

    private function hasSkipClass(string $className): bool
    {
        return preg_match('/(^|\s)' . self::SKIP_CLASS . '(\s|$)/', $className) === 1;
    }

    /**
     * @return list<string>
     */
    private function captions(string $html): array
    {
        if (preg_match_all('~<figcaption\b[^>]*>(.*?)</figcaption>~is', $html, $matches) === false) {
            return [];
        }
        $out = [];
        foreach ($matches[1] as $caption) {
            $text = trim(html_entity_decode(strip_tags($caption), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($text !== '') {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * Remove shortcodes and their enclosed content. Kept shortcodes lose
     * their tags but keep their enclosed text. Unregistered shortcodes are
     * recognised by shape (lower-case name, attributes or nothing).
     */
    private function stripShortcodes(string $html): string
    {
        if (!str_contains($html, '[')) {
            return $html;
        }
        $names = '[a-z0-9_\-]+';
        if ($this->registeredShortcodes !== []) {
            $quoted = array_map(static fn (string $n): string => preg_quote($n, '~'), $this->registeredShortcodes);
            $names = '(?:' . implode('|', $quoted) . '|[a-z0-9_\-]+)';
        }
        $pattern = '~\[(' . $names . ')(?=[\s\]/])((?:\s+[^\]]*)?)(?:/\]|\](?:(.*?)\[/\1\])?)~s';
        $previous = null;
        $guard = 0;
        while ($previous !== $html && $guard < 10) {
            $previous = $html;
            $guard++;
            $html = (string) preg_replace_callback($pattern, function (array $m): string {
                $name = $m[1];
                $attributes = $m[2];
                $registered = in_array($name, $this->registeredShortcodes, true);
                if (!$registered && $attributes !== '' && !str_contains($attributes, '=')) {
                    return $m[0];
                }
                if (in_array($name, $this->keepShortcodes, true)) {
                    return isset($m[3]) ? ' ' . $m[3] . ' ' : ' ';
                }
                return ' ';
            }, $html);
        }
        // Stray closing tags of kept shortcodes.
        return (string) preg_replace('~\[/[a-z0-9_\-]+\]~', ' ', $html);
    }

    /**
     * @return list<string>
     */
    private function htmlToParagraphs(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument('1.0', 'UTF-8');
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"?><!DOCTYPE html><html><body><div>' . $html . '</div></body></html>',
            LIBXML_NONET | LIBXML_COMPACT
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return $this->plainParagraphs(strip_tags($html));
        }
        $body = $document->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return [];
        }
        $state = ['paragraphs' => [], 'current' => ''];
        $this->walk($body, $state);
        $this->flush($state);

        return $state['paragraphs'];
    }

    /**
     * @param array{paragraphs: list<string>, current: string} $state
     */
    private function walk(\DOMNode $node, array &$state): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $parts = preg_split('/\n[ \t\r]*\n/', $child->data);
                if ($parts === false) {
                    continue;
                }
                foreach ($parts as $i => $part) {
                    if ($i > 0) {
                        $this->flush($state);
                    }
                    $state['current'] .= $part;
                }
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if ($this->isDropped($child, $tag)) {
                if ($this->keepCaptions && $tag === 'figure' && !$this->hasSkipClass($child->getAttribute('class'))) {
                    foreach ($child->getElementsByTagName('figcaption') as $caption) {
                        $this->flush($state);
                        $state['current'] = $caption->textContent;
                        $this->flush($state);
                    }
                }
                continue;
            }
            if ($tag === 'br') {
                $state['current'] .= ' ';
                continue;
            }
            $isBlock = in_array($tag, self::BLOCK_TAGS, true);
            if ($isBlock) {
                $this->flush($state);
            }
            $this->walk($child, $state);
            if ($isBlock) {
                $this->flush($state);
            }
        }
    }

    private function isDropped(\DOMElement $element, string $tag): bool
    {
        if ($this->hasSkipClass($element->getAttribute('class'))) {
            return true;
        }
        if ($this->keepTables && ($tag === 'table' || ($tag === 'figure' && $element->getElementsByTagName('table')->length > 0))) {
            return false;
        }
        if ($tag === 'figcaption' && $this->keepCaptions) {
            return false;
        }
        if ($tag === 'sup' && ($element->hasAttribute('data-fn') || preg_match('/(^|\s)fn(\s|$)/', $element->getAttribute('class')) === 1)) {
            return true;
        }

        return in_array($tag, self::DROP_TAGS, true);
    }

    /**
     * @param array{paragraphs: list<string>, current: string} $state
     */
    private function flush(array &$state): void
    {
        $text = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $state['current']));
        $state['current'] = '';
        if ($text !== '') {
            $state['paragraphs'][] = $text;
        }
    }

    /**
     * @return list<string>
     */
    private function plainParagraphs(string $text): array
    {
        $parts = preg_split('/\n[ \t\r]*\n/', $text);
        $out = [];
        foreach ($parts === false ? [] : $parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }
}
