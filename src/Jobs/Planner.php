<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Jobs;

use IndianOpinion\ReadAloud\Data\Records;
use IndianOpinion\ReadAloud\Settings\Settings;
use IndianOpinion\ReadAloud\Text\Extractor;
use IndianOpinion\ReadAloud\Text\Hashes;
use IndianOpinion\ReadAloud\Text\Lexicon;
use IndianOpinion\ReadAloud\Text\Normaliser;

/**
 * Eligibility (PRD 4.1), spoken text (4.3) and voice choice (4.4).
 * Local work only; never a network call.
 */
final class Planner
{
    public function __construct(private readonly Settings $settings, private readonly Records $records)
    {
    }

    /**
     * Opt-in and post-state rules that need no text.
     *
     * @return array{eligible: bool, reason: string}
     */
    public function base(\WP_Post $post): array
    {
        if (!in_array($post->post_type, $this->settings->strings('post_types'), true)) {
            return ['eligible' => false, 'reason' => 'post_type'];
        }
        if ($post->post_status !== 'publish') {
            return ['eligible' => false, 'reason' => 'status'];
        }
        if ($post->post_password !== '') {
            return ['eligible' => false, 'reason' => 'password'];
        }
        $mode = $this->records->mode((int) $post->ID);
        if ($mode === Records::MODE_OFF) {
            return ['eligible' => false, 'reason' => 'opted_out'];
        }
        if ($mode === Records::MODE_INHERIT && !$this->categoryOptedIn($post)) {
            return ['eligible' => false, 'reason' => 'not_opted_in'];
        }
        if (apply_filters('read_aloud_is_eligible', true, $post) === false) {
            return ['eligible' => false, 'reason' => 'filtered'];
        }

        return ['eligible' => true, 'reason' => ''];
    }

    /** What "inherit" resolves to for this post. */
    public function categoryOptedIn(\WP_Post $post): bool
    {
        $optedIn = $this->settings->ints('categories');
        if ($optedIn === []) {
            return false;
        }
        $terms = get_the_terms($post, 'category');
        if (!is_array($terms)) {
            return false;
        }
        $children = $this->settings->bool('include_children');
        foreach ($terms as $term) {
            if (!$term instanceof \WP_Term) {
                continue;
            }
            if (in_array((int) $term->term_id, $optedIn, true)) {
                return true;
            }
            if ($children) {
                foreach (get_ancestors((int) $term->term_id, 'category', 'taxonomy') as $ancestor) {
                    if (in_array((int) $ancestor, $optedIn, true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function extractor(): Extractor
    {
        $skip = apply_filters('read_aloud_skip_block_types', $this->settings->strings('skip_blocks'));
        $keep = apply_filters('read_aloud_keep_shortcodes', []);
        global $shortcode_tags;
        $registered = is_array($shortcode_tags) ? array_map('strval', array_keys($shortcode_tags)) : [];

        return new Extractor(
            new Normaliser(),
            is_array($skip) ? array_values(array_map('strval', $skip)) : Extractor::DEFAULT_SKIP_BLOCKS,
            is_array($keep) ? array_values(array_map('strval', $keep)) : [],
            $this->settings->bool('keep_tables'),
            $this->settings->bool('keep_captions'),
            $registered
        );
    }

    /** The spoken text: extraction, normalisation and the filter. Hashed as is. */
    public function spokenText(\WP_Post $post): string
    {
        $text = $this->extractor()->extract((string) $post->post_title, (string) $post->post_content);
        $filtered = apply_filters('read_aloud_spoken_text', $text, $post);

        return is_string($filtered) ? $filtered : $text;
    }

    /** BCP 47 locale of a post. */
    public function locale(\WP_Post $post): string
    {
        $site = str_replace('_', '-', (string) get_locale());
        $locale = apply_filters('read_aloud_post_locale', $site, $post);

        return is_string($locale) && $locale !== '' ? str_replace('_', '-', $locale) : $site;
    }

    /**
     * First voice map row for a locale: exact tag, then language subtag.
     *
     * @return array{locale: string, voice: string, model: string, speed: float, format: string}|null
     */
    public function voiceFor(string $locale): ?array
    {
        $map = $this->settings->voiceMap();
        $wanted = strtolower($locale);
        foreach ($map as $row) {
            if (strtolower($row['locale']) === $wanted) {
                return $row;
            }
        }
        $language = strtolower(explode('-', $locale)[0]);
        foreach ($map as $row) {
            if (strtolower($row['locale']) === $language) {
                return $row;
            }
        }

        return null;
    }

    public function lexicon(): Lexicon
    {
        return new Lexicon($this->settings->lexiconEntries());
    }

    /**
     * The settings that shape the sound for a voice row.
     *
     * @param array{locale: string, voice: string, model: string, speed: float, format: string} $voice
     * @return array<string, scalar|null>
     */
    public function renderSettings(array $voice, string $locale): array
    {
        return [
            'provider' => $this->settings->string('provider'),
            'model' => $voice['model'],
            'voice' => $voice['voice'],
            'locale' => $locale,
            'format' => $voice['format'],
            'speed' => $voice['speed'],
            'lexicon' => $this->lexicon()->version(),
            'intro' => $this->settings->string('intro'),
            'outro' => $this->settings->string('outro'),
        ];
    }

    public function maxChars(\WP_Post $post): int
    {
        return (int) apply_filters('read_aloud_max_chars', $this->settings->int('max_chars'), $post);
    }

    /** Full rule set of 4.1 plus the text and hashes. */
    public function plan(\WP_Post $post): Plan
    {
        $base = $this->base($post);
        if (!$base['eligible']) {
            return new Plan(false, $base['reason']);
        }
        $text = $this->spokenText($post);
        $hash = Hashes::content($text);
        $chars = mb_strlen($text, 'UTF-8');
        $locale = $this->locale($post);
        if ($chars < $this->settings->int('min_chars')) {
            return new Plan(false, 'too_short', $text, $hash, $locale, null, '', [], $chars);
        }
        if ($chars > $this->maxChars($post)) {
            return new Plan(false, 'too_long', $text, $hash, $locale, null, '', [], $chars);
        }
        $voice = $this->voiceFor($locale);
        if ($voice === null) {
            return new Plan(false, 'no_voice', $text, $hash, $locale, null, '', [], $chars);
        }
        $render = $this->renderSettings($voice, $locale);

        return new Plan(true, '', $text, $hash, $locale, $voice, Hashes::render($render), $render, $chars);
    }

    /** Text sent to the provider: lexicon applied, intro and outro added. */
    public function synthesisText(string $spokenText): string
    {
        $text = $this->lexicon()->apply($spokenText);
        $intro = trim($this->settings->string('intro'));
        $outro = trim($this->settings->string('outro'));

        return trim(($intro !== '' ? $intro . "\n" : '') . $text . ($outro !== '' ? "\n" . $outro : ''));
    }
}
