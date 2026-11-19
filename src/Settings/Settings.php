<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Settings;

use IndianOpinion\ReadAloud\Audio\AudioFormat;
use IndianOpinion\ReadAloud\Provider\ProviderRegistry;
use IndianOpinion\ReadAloud\Text\Extractor;
use IndianOpinion\ReadAloud\Text\Lexicon;

/**
 * Plugin settings: one small autoloaded option, plus the API key in its own
 * non-autoloaded option (or the READ_ALOUD_API_KEY constant).
 */
final class Settings
{
    public const OPTION = 'read_aloud_settings';
    public const OPTION_KEY = 'read_aloud_api_key';
    public const OPTION_SECRET = 'read_aloud_secret';

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly ProviderRegistry $providers)
    {
    }

    /** @return array<string, mixed> */
    public static function baseDefaults(): array
    {
        return [
            'provider' => '',
            'provider_settings' => [],
            'voice_map' => [],
            'categories' => [],
            'include_children' => false,
            'post_types' => ['post'],
            'cap_chars' => 50000,
            'cap_generations' => 20,
            'price_per_million' => '',
            'cap_money' => '',
            'show_player' => true,
            'stale_policy' => 'hide',
            'debounce_minutes' => 10,
            'max_wait_minutes' => 60,
            'min_chars' => 200,
            'max_chars' => 60000,
            'skip_blocks' => Extractor::DEFAULT_SKIP_BLOCKS,
            'keep_tables' => false,
            'keep_captions' => false,
            'intro' => '',
            'outro' => '',
            'lexicon' => [],
            'insert' => 'before',
            'label' => '',
            'disclosure' => '',
            'speed_buttons' => false,
            'download_link' => false,
            'feed_enclosure' => false,
            'chunk_cache' => true,
            'grace_hours' => 48,
            'concurrency' => 1,
            'keep_files' => false,
            'check_file_exists' => false,
            'request_timeout' => 60,
        ];
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        $filtered = apply_filters('read_aloud_default_settings', self::baseDefaults());

        return is_array($filtered) ? $filtered + self::baseDefaults() : self::baseDefaults();
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->cache === null) {
            $stored = get_option(self::OPTION, []);
            $this->cache = (is_array($stored) ? array_intersect_key($stored, self::baseDefaults()) : []) + $this->defaults();
        }

        return $this->cache;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    public function int(string $key): int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : 0;
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function string(string $key): string
    {
        $value = $this->get($key);

        return is_scalar($value) ? (string) $value : '';
    }

    /** @return list<string> */
    public function strings(string $key): array
    {
        $value = $this->get($key);

        return is_array($value) ? array_values(array_map('strval', array_filter($value, 'is_scalar'))) : [];
    }

    /** @return list<int> */
    public function ints(string $key): array
    {
        $value = $this->get($key);

        return is_array($value) ? array_values(array_map('intval', array_filter($value, 'is_numeric'))) : [];
    }

    /**
     * Store already-sanitised settings.
     *
     * @param array<string, mixed> $settings
     */
    public function replace(array $settings): void
    {
        update_option(self::OPTION, array_intersect_key($settings, self::baseDefaults()) + self::baseDefaults(), true);
        $this->cache = null;
    }

    /** Forget the in-memory copy. */
    public function refresh(): void
    {
        $this->cache = null;
    }

    /**
     * A random per-site secret, created on first use and never shown. It
     * keys file and chunk names so they cannot be guessed from public text.
     */
    public function secret(): string
    {
        $secret = get_option(self::OPTION_SECRET, '');
        if (is_string($secret) && strlen($secret) >= 32) {
            return $secret;
        }
        add_option(self::OPTION_SECRET, bin2hex(random_bytes(32)), '', false);
        // Re-read: if two requests raced, both use the value that was stored.
        $secret = get_option(self::OPTION_SECRET, '');

        return is_string($secret) ? $secret : '';
    }

    public function keyFromConstant(): bool
    {
        return defined('READ_ALOUD_API_KEY') && is_string(constant('READ_ALOUD_API_KEY')) && constant('READ_ALOUD_API_KEY') !== '';
    }

    public function apiKey(): string
    {
        if ($this->keyFromConstant()) {
            return (string) constant('READ_ALOUD_API_KEY');
        }
        $key = get_option(self::OPTION_KEY, '');

        return is_string($key) ? $key : '';
    }

    public function hasSavedKey(): bool
    {
        return $this->apiKey() !== '';
    }

    /** An empty value keeps the existing key. */
    public function saveApiKey(string $key): void
    {
        $key = trim($key);
        if ($key === '' || $this->keyFromConstant()) {
            return;
        }
        update_option(self::OPTION_KEY, $key, false);
    }

    /**
     * Settings for the active provider, with the key added.
     *
     * @return array<string, mixed>
     */
    public function providerSettings(?string $providerId = null): array
    {
        $providerId ??= $this->string('provider');
        $all = $this->get('provider_settings');
        $settings = is_array($all) && isset($all[$providerId]) && is_array($all[$providerId]) ? $all[$providerId] : [];
        $settings['api_key'] = $this->apiKey();
        $settings['timeout'] = $this->int('request_timeout');

        return $settings;
    }

    /** @return list<array{written: string, spoken: string, case_sensitive: bool}> */
    public function lexiconEntries(): array
    {
        $entries = $this->get('lexicon');
        $out = [];
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (is_array($entry) && isset($entry['written'], $entry['spoken'])) {
                $out[] = [
                    'written' => (string) $entry['written'],
                    'spoken' => (string) $entry['spoken'],
                    'case_sensitive' => (bool) ($entry['case_sensitive'] ?? true),
                ];
            }
        }

        return $out;
    }

    /** @return list<array{locale: string, voice: string, model: string, speed: float, format: string}> */
    public function voiceMap(): array
    {
        $rows = $this->get('voice_map');
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['locale'], $row['voice'])) {
                $out[] = [
                    'locale' => (string) $row['locale'],
                    'voice' => (string) $row['voice'],
                    'model' => (string) ($row['model'] ?? ''),
                    'speed' => (float) ($row['speed'] ?? 1.0),
                    'format' => (string) ($row['format'] ?? AudioFormat::MP3),
                ];
            }
        }

        return $out;
    }

    /**
     * Validate and sanitise a submitted settings form.
     *
     * Invalid values are rejected with a message and the current value is
     * kept for that field.
     *
     * @param array<string, mixed> $raw
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public function sanitize(array $raw): array
    {
        $current = $this->all();
        $out = $current;
        $errors = [];

        $int = function (string $key, int $min, int $max, string $label) use ($raw, &$out, &$errors): void {
            if (!array_key_exists($key, $raw)) {
                return;
            }
            $value = is_string($raw[$key]) ? trim($raw[$key]) : $raw[$key];
            if (!is_numeric($value) || (string) (int) $value !== (string) $value && !is_int($value)) {
                $errors[] = sprintf('%s must be a whole number.', $label);
                return;
            }
            $value = (int) $value;
            if ($value < $min || $value > $max) {
                $errors[] = sprintf('%s must be between %s and %s.', $label, number_format($min), number_format($max));
                return;
            }
            $out[$key] = $value;
        };
        $bool = function (string $key) use ($raw, &$out): void {
            $out[$key] = !empty($raw[$key]);
        };
        $choice = function (string $key, array $allowed, string $label) use ($raw, &$out, &$errors): void {
            if (!array_key_exists($key, $raw)) {
                return;
            }
            if (!in_array($raw[$key], $allowed, true)) {
                $errors[] = sprintf('%s has an invalid value.', $label);
                return;
            }
            $out[$key] = $raw[$key];
        };
        $text = function (string $key, int $max) use ($raw, &$out): void {
            if (!array_key_exists($key, $raw) || !is_string($raw[$key])) {
                return;
            }
            $out[$key] = mb_substr(sanitize_text_field($raw[$key]), 0, $max);
        };

        $int('cap_chars', 0, 100000000, 'Daily character cap');
        $int('cap_generations', 0, 100000, 'Daily generation cap');
        $int('debounce_minutes', 0, 1440, 'Debounce');
        $int('max_wait_minutes', 1, 10080, 'Maximum wait');
        $int('min_chars', 0, 1000000, 'Minimum length');
        $int('max_chars', 1, 10000000, 'Maximum length');
        $int('grace_hours', 0, 8760, 'Grace period');
        $int('concurrency', 1, 4, 'Concurrency');
        $int('request_timeout', 5, 600, 'Request timeout');
        if ((int) $out['max_wait_minutes'] < (int) $out['debounce_minutes']) {
            $errors[] = 'Maximum wait must not be shorter than the debounce.';
            $out['max_wait_minutes'] = $current['max_wait_minutes'];
            $out['debounce_minutes'] = $current['debounce_minutes'];
        }
        if ((int) $out['max_chars'] < (int) $out['min_chars']) {
            $errors[] = 'Maximum length must not be shorter than the minimum length.';
            $out['max_chars'] = $current['max_chars'];
            $out['min_chars'] = $current['min_chars'];
        }

        foreach (['include_children', 'show_player', 'keep_tables', 'keep_captions', 'speed_buttons', 'download_link', 'feed_enclosure', 'chunk_cache', 'keep_files', 'check_file_exists'] as $flag) {
            if (array_key_exists('_present', $raw) || array_key_exists($flag, $raw)) {
                $bool($flag);
            }
        }
        $choice('stale_policy', ['hide', 'note'], 'Stale policy');
        $choice('insert', ['before', 'after', 'none'], 'Insert player');
        $text('label', 200);
        $text('intro', 500);
        $text('outro', 500);
        if (array_key_exists('disclosure', $raw) && is_string($raw['disclosure'])) {
            $disclosure = mb_substr(sanitize_text_field($raw['disclosure']), 0, 300);
            // The screen cannot blank the disclosure; empty means the default text.
            $out['disclosure'] = $disclosure;
        }

        foreach (['price_per_million', 'cap_money'] as $money) {
            if (!array_key_exists($money, $raw)) {
                continue;
            }
            $value = is_scalar($raw[$money]) ? trim((string) $raw[$money]) : '';
            if ($value === '') {
                $out[$money] = '';
            } elseif (preg_match('/^\d{1,9}(\.\d{1,6})?$/', $value) !== 1) {
                $errors[] = $money === 'price_per_million'
                    ? 'Price per million characters must be a positive decimal number.'
                    : 'Daily money cap must be a positive decimal number.';
            } else {
                $out[$money] = $value;
            }
        }

        if (array_key_exists('post_types', $raw)) {
            $types = is_array($raw['post_types']) ? $raw['post_types'] : [];
            $clean = [];
            foreach ($types as $type) {
                if (is_string($type) && post_type_exists($type)) {
                    $clean[] = sanitize_key($type);
                }
            }
            if ($clean === []) {
                $errors[] = 'Choose at least one post type.';
            } else {
                $out['post_types'] = array_values(array_unique($clean));
            }
        }
        if (array_key_exists('categories', $raw) || array_key_exists('_present', $raw)) {
            $categories = isset($raw['categories']) && is_array($raw['categories']) ? $raw['categories'] : [];
            $out['categories'] = array_values(array_unique(array_filter(array_map('intval', $categories), static fn (int $id): bool => $id > 0)));
        }
        if (array_key_exists('skip_blocks', $raw) && is_string($raw['skip_blocks'])) {
            $blocks = [];
            foreach (preg_split('/[\s,]+/', $raw['skip_blocks']) ?: [] as $name) {
                if ($name === '') {
                    continue;
                }
                if (preg_match('~^[a-z0-9-]+/[a-z0-9-]+$~', $name) !== 1) {
                    $errors[] = sprintf('"%s" is not a block type name.', $name);
                    continue;
                }
                $blocks[] = $name;
            }
            $out['skip_blocks'] = array_values(array_unique($blocks));
        }
        if (array_key_exists('lexicon', $raw) && is_string($raw['lexicon'])) {
            $out['lexicon'] = Lexicon::parse($raw['lexicon']);
        }
        if (array_key_exists('voice_map', $raw) && is_string($raw['voice_map'])) {
            [$map, $mapErrors] = self::parseVoiceMap($raw['voice_map']);
            if ($mapErrors !== []) {
                array_push($errors, ...$mapErrors);
            } else {
                $out['voice_map'] = $map;
            }
        }

        if (array_key_exists('provider', $raw)) {
            $providerId = is_string($raw['provider']) ? $raw['provider'] : '';
            $factories = $this->providers->factories();
            if ($providerId !== '' && !isset($factories[$providerId])) {
                $errors[] = 'Unknown provider.';
            } else {
                $out['provider'] = $providerId;
                if ($providerId !== '') {
                    $fields = isset($raw['provider_fields']) && is_array($raw['provider_fields']) ? $raw['provider_fields'] : [];
                    [$providerSettings, $providerErrors] = $this->sanitizeProvider($providerId, $fields);
                    if ($providerErrors !== []) {
                        array_push($errors, ...$providerErrors);
                        $out['provider'] = $current['provider'];
                    } else {
                        $all = is_array($current['provider_settings']) ? $current['provider_settings'] : [];
                        $all[$providerId] = $providerSettings;
                        $out['provider_settings'] = $all;
                    }
                }
            }
        }

        return [$out, $errors];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private function sanitizeProvider(string $providerId, array $fields): array
    {
        $provider = $this->providers->make($providerId, []);
        $clean = [];
        foreach ($provider->settingsFields() as $field) {
            if ($field['secret']) {
                continue;
            }
            // The caller passes unslashed input (the settings screen calls
            // wp_unslash() once); a second pass would eat backslashes in a
            // JSON body template.
            $value = isset($fields[$field['key']]) && is_scalar($fields[$field['key']]) ? (string) $fields[$field['key']] : '';
            $clean[$field['key']] = match ($field['type']) {
                'textarea' => trim(str_replace("\r\n", "\n", $value)),
                'url' => trim($value),
                'number' => preg_replace('/[^0-9]/', '', $value) ?? '',
                default => sanitize_text_field($value),
            };
            if ($clean[$field['key']] === '' && isset($field['default'])) {
                $clean[$field['key']] = $field['default'];
            }
        }
        $check = $clean;
        $check['api_key'] = $this->apiKey();

        return [$clean, $provider->validateSettings($check)];
    }

    /**
     * Parse "locale | voice | model | speed | format" lines.
     *
     * @return array{0: list<array{locale: string, voice: string, model: string, speed: float, format: string}>, 1: list<string>}
     */
    public static function parseVoiceMap(string $lines): array
    {
        $map = [];
        $errors = [];
        foreach (preg_split('/\R/u', $lines) ?: [] as $number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $locale = str_replace('_', '-', $parts[0]);
            $voice = $parts[1] ?? '';
            $model = $parts[2] ?? '';
            $speed = ($parts[3] ?? '') === '' ? 1.0 : $parts[3];
            $format = strtolower(($parts[4] ?? '') === '' ? AudioFormat::MP3 : $parts[4]);
            $lineNo = $number + 1;
            if (preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $locale) !== 1) {
                $errors[] = sprintf('Voice map line %d: "%s" is not a language tag such as en-GB.', $lineNo, $locale);
                continue;
            }
            if ($voice === '' || preg_match('/^[\w.\-:\/ ]{1,128}$/u', $voice) !== 1) {
                $errors[] = sprintf('Voice map line %d: a voice id is required.', $lineNo);
                continue;
            }
            if (!is_numeric($speed) || (float) $speed < 0.5 || (float) $speed > 2.0) {
                $errors[] = sprintf('Voice map line %d: speed must be between 0.5 and 2.0.', $lineNo);
                continue;
            }
            if (!AudioFormat::isKnown($format)) {
                $errors[] = sprintf('Voice map line %d: format must be mp3, wav or ogg.', $lineNo);
                continue;
            }
            $map[] = [
                'locale' => $locale,
                'voice' => $voice,
                'model' => sanitize_text_field($model),
                'speed' => (float) $speed,
                'format' => $format,
            ];
        }

        return [$map, $errors];
    }

    /**
     * @param list<array{locale: string, voice: string, model: string, speed: float, format: string}> $map
     */
    public static function voiceMapToText(array $map): string
    {
        $lines = [];
        foreach ($map as $row) {
            $lines[] = implode(' | ', [$row['locale'], $row['voice'], $row['model'], rtrim(rtrim(number_format($row['speed'], 2, '.', ''), '0'), '.'), $row['format']]);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{written: string, spoken: string, case_sensitive: bool}> $entries
     */
    public static function lexiconToText(array $entries): string
    {
        $lines = [];
        foreach ($entries as $entry) {
            $lines[] = $entry['written'] . ' = ' . $entry['spoken'] . ($entry['case_sensitive'] ? '' : ' (i)');
        }

        return implode("\n", $lines);
    }
}
