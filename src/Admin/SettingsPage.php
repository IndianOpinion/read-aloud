<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Admin;

use IndianOpinion\ReadAloud\Plugin;
use IndianOpinion\ReadAloud\Review\Review;
use IndianOpinion\ReadAloud\Settings\Settings;

/**
 * Settings > Read aloud. Saving needs the settings capability and a nonce.
 */
final class SettingsPage
{
    public const SLUG = 'read-aloud';
    public const NONCE = 'read_aloud_settings';

    /** Settings that change the sound; a change marks files outdated. */
    private const RENDER_KEYS = ['provider', 'voice_map', 'lexicon', 'intro', 'outro'];

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_read_aloud_save', [$this, 'onSave']);
        add_action('admin_post_read_aloud_test_voice', [$this, 'onTestVoice']);
        add_action('admin_post_read_aloud_clear_hold', [$this, 'onClearHold']);
    }

    public function menu(): void
    {
        add_options_page(
            __('Read aloud', 'read-aloud'),
            __('Read aloud', 'read-aloud'),
            (string) apply_filters('read_aloud_capability', 'manage_options', 'settings'),
            self::SLUG,
            [$this, 'render']
        );
    }

    private function redirect(string $notice): void
    {
        set_transient('read_aloud_notice_' . get_current_user_id(), $notice, 300);
        wp_safe_redirect(admin_url('options-general.php?page=' . self::SLUG));
        exit;
    }

    public function onSave(): void
    {
        if (!Review::canManage()) {
            wp_die(esc_html__('You cannot change these settings.', 'read-aloud'), 403);
        }
        check_admin_referer(self::NONCE);
        $raw = isset($_POST['read_aloud']) && is_array($_POST['read_aloud']) ? wp_unslash($_POST['read_aloud']) : [];
        $errors = $this->save(is_array($raw) ? $raw : []);
        $this->redirect($errors === [] ? 'saved' : implode("\n", $errors));
    }

    /**
     * Validate and store a submitted form.
     *
     * @param array<string, mixed> $raw
     * @return list<string> Errors; the valid parts are saved.
     */
    public function save(array $raw): array
    {
        $settings = $this->plugin->settings();
        $before = $settings->all();
        $raw['_present'] = '1';
        [$clean, $errors] = $settings->sanitize($raw);
        $settings->replace($clean);
        if (isset($raw['api_key']) && is_string($raw['api_key'])) {
            $settings->saveApiKey($raw['api_key']);
        }
        $guard = $this->plugin->guard();
        $wantEnabled = !empty($raw['enabled']);
        if ($wantEnabled !== $guard->settingEnabled()) {
            $guard->setEnabled($wantEnabled, 'user:' . get_current_user_id());
            if ($wantEnabled) {
                $this->plugin->queue()->redispatchWaiting();
            }
        }
        if (isset($raw['provider']) && $guard->clearHold('settings saved')) {
            $this->plugin->queue()->redispatchWaiting();
        }
        $after = $settings->all();
        foreach (self::RENDER_KEYS as $key) {
            if (($before[$key] ?? null) != ($after[$key] ?? null) || ($key === 'provider' && ($before['provider_settings'] ?? null) != ($after['provider_settings'] ?? null))) {
                $this->plugin->maintenance()->markOutdated(0);
                break;
            }
        }
        $this->plugin->log()->add('settings_saved', 0, ['errors' => count($errors)]);

        return $errors;
    }

    public function onTestVoice(): void
    {
        if (!Review::canManage()) {
            wp_die(esc_html__('You cannot change these settings.', 'read-aloud'), 403);
        }
        check_admin_referer('read_aloud_test_voice');
        $result = $this->plugin->generator()->testVoice();
        set_transient('read_aloud_test_' . get_current_user_id(), $result, 300);
        $this->redirect($result['ok'] ? 'test_ok' : 'Test voice: ' . $result['message']);
    }

    public function onClearHold(): void
    {
        if (!Review::canManage()) {
            wp_die(esc_html__('You cannot change these settings.', 'read-aloud'), 403);
        }
        check_admin_referer('read_aloud_clear_hold');
        if ($this->plugin->guard()->clearHold('user:' . get_current_user_id())) {
            $this->plugin->queue()->redispatchWaiting();
        }
        $this->redirect('hold_cleared');
    }

    public function render(): void
    {
        if (!Review::canManage()) {
            wp_die(esc_html__('You cannot see these settings.', 'read-aloud'), 403);
        }
        echo $this->html(); // phpcs:ignore -- every part is escaped in html().
    }

    public function html(): string
    {
        $settings = $this->plugin->settings();
        $s = $settings->all();
        $guard = $this->plugin->guard();
        $userId = get_current_user_id();
        $out = '<div class="wrap"><h1>' . esc_html__('Read aloud', 'read-aloud') . '</h1>';

        $notice = get_transient('read_aloud_notice_' . $userId);
        if (is_string($notice) && $notice !== '') {
            delete_transient('read_aloud_notice_' . $userId);
            $ok = in_array($notice, ['saved', 'test_ok', 'hold_cleared'], true);
            $text = match ($notice) {
                'saved' => __('Settings saved.', 'read-aloud'),
                'test_ok' => __('Test voice made. Listen below.', 'read-aloud'),
                'hold_cleared' => __('Hold cleared.', 'read-aloud'),
                default => $notice,
            };
            $out .= '<div class="notice ' . ($ok ? 'notice-success' : 'notice-error') . '"><p>' . nl2br(esc_html($text)) . '</p></div>';
        }
        $test = get_transient('read_aloud_test_' . $userId);
        if (is_array($test) && !empty($test['ok']) && is_string($test['url'] ?? null)) {
            delete_transient('read_aloud_test_' . $userId);
            $out .= '<p><audio controls preload="none" src="' . esc_url($test['url']) . '"></audio></p>';
        }

        $out .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $out .= '<input type="hidden" name="action" value="read_aloud_save">' . wp_nonce_field(self::NONCE, '_wpnonce', true, false);
        $out .= '<h2>' . esc_html__('Generation', 'read-aloud') . '</h2><table class="form-table" role="presentation">';
        if ($guard->constantDisabled()) {
            $out .= $this->row(__('Generation enabled', 'read-aloud'), '<p>' . esc_html__('READ_ALOUD_DISABLED is defined: generation is off and this switch has no effect.', 'read-aloud') . '</p>');
        }
        $out .= $this->row(__('Generation enabled', 'read-aloud'), $this->checkbox('enabled', $guard->settingEnabled(), __('Allow calls to the speech provider. Off is the kill switch; approved audio keeps playing.', 'read-aloud')));
        $out .= $this->row(__('Show player', 'read-aloud'), $this->checkbox('show_player', (bool) $s['show_player'], __('Show approved audio to readers.', 'read-aloud')));

        $labels = $this->plugin->providers()->labels();
        $options = '<option value="">' . esc_html__('None', 'read-aloud') . '</option>';
        foreach ($labels as $id => $label) {
            $options .= '<option value="' . esc_attr($id) . '"' . selected($s['provider'], $id, false) . '>' . esc_html($label) . '</option>';
        }
        $out .= $this->row(__('Provider', 'read-aloud'), '<select name="read_aloud[provider]">' . $options . '</select><p class="description">'
            . esc_html__('Post text is sent to this third-party service. Check its data terms before you turn it on. Only published posts are ever sent.', 'read-aloud') . '</p>');
        $out .= '</table>';

        $providerId = (string) $s['provider'];
        if ($providerId !== '') {
            $out .= $this->providerFields($providerId);
        }

        $out .= '<h2>' . esc_html__('What is read', 'read-aloud') . '</h2><table class="form-table" role="presentation">';
        $categories = get_categories(['hide_empty' => false]);
        $boxes = '';
        foreach (is_array($categories) ? $categories : [] as $category) {
            if (!$category instanceof \WP_Term) {
                continue;
            }
            $checked = in_array((int) $category->term_id, $settings->ints('categories'), true);
            $boxes .= '<label style="display:block"><input type="checkbox" name="read_aloud[categories][]" value="' . esc_attr((string) $category->term_id) . '"' . checked($checked, true, false) . '> ' . esc_html($category->name) . '</label>';
        }
        $out .= $this->row(__('Opted-in categories', 'read-aloud'), $boxes . '<p class="description">' . esc_html__('Opting a category in queues nothing for existing posts. Use a backfill for those.', 'read-aloud') . '</p>');
        $out .= $this->row(__('Include child categories', 'read-aloud'), $this->checkbox('include_children', (bool) $s['include_children'], ''));
        $types = '';
        foreach (get_post_types(['public' => true], 'objects') as $type) {
            if (!$type instanceof \WP_Post_Type) {
                continue;
            }
            $types .= '<label style="display:block"><input type="checkbox" name="read_aloud[post_types][]" value="' . esc_attr($type->name) . '"' . checked(in_array($type->name, $settings->strings('post_types'), true), true, false) . '> ' . esc_html((string) $type->label) . '</label>';
        }
        $out .= $this->row(__('Allowed post types', 'read-aloud'), $types);
        $out .= $this->row(__('Minimum length', 'read-aloud'), $this->number('min_chars', (int) $s['min_chars']));
        $out .= $this->row(__('Maximum length', 'read-aloud'), $this->number('max_chars', (int) $s['max_chars']));
        $out .= $this->row(__('Skipped block types', 'read-aloud'), '<textarea name="read_aloud[skip_blocks]" rows="4" cols="60">' . esc_textarea(implode("\n", $settings->strings('skip_blocks'))) . '</textarea>');
        $out .= $this->row(__('Tables', 'read-aloud'), $this->checkbox('keep_tables', (bool) $s['keep_tables'], __('Read tables (dropped by default).', 'read-aloud')));
        $out .= $this->row(__('Captions', 'read-aloud'), $this->checkbox('keep_captions', (bool) $s['keep_captions'], __('Read figure captions (dropped by default).', 'read-aloud')));
        $out .= $this->row(__('Intro line', 'read-aloud'), $this->text('intro', (string) $s['intro']));
        $out .= $this->row(__('Outro line', 'read-aloud'), $this->text('outro', (string) $s['outro']));
        $out .= '</table>';

        $out .= '<h2>' . esc_html__('Voices', 'read-aloud') . '</h2><table class="form-table" role="presentation">';
        $out .= $this->row(__('Voice map', 'read-aloud'), '<textarea name="read_aloud[voice_map]" rows="4" cols="60">' . esc_textarea(Settings::voiceMapToText($settings->voiceMap())) . '</textarea><p class="description">'
            . esc_html__('One per line: locale | voice | model | speed | format. Example: en-GB | narrator-1 | | 1.0 | mp3', 'read-aloud') . '</p>');
        $out .= $this->row(__('Pronunciation lexicon', 'read-aloud'), '<textarea name="read_aloud[lexicon]" rows="4" cols="60">' . esc_textarea(Settings::lexiconToText($settings->lexiconEntries())) . '</textarea><p class="description">'
            . esc_html__('One per line: written = spoken. Add (i) at the end for a case-insensitive entry.', 'read-aloud') . '</p>');
        $out .= '</table>';

        $out .= '<h2>' . esc_html__('Spending', 'read-aloud') . '</h2><table class="form-table" role="presentation">';
        $out .= $this->row(__('Daily character cap', 'read-aloud'), $this->number('cap_chars', (int) $s['cap_chars']) . '<p class="description">' . esc_html__('0 means no generation today.', 'read-aloud') . '</p>');
        $out .= $this->row(__('Daily generation cap', 'read-aloud'), $this->number('cap_generations', (int) $s['cap_generations']));
        $out .= $this->row(__('Price per million characters', 'read-aloud'), $this->text('price_per_million', (string) $s['price_per_million']) . '<p class="description">' . esc_html__('Your provider\'s price. Empty turns the money cap off.', 'read-aloud') . '</p>');
        $out .= $this->row(__('Daily money cap', 'read-aloud'), $this->text('cap_money', (string) $s['cap_money']));
        $out .= $this->row(__('Debounce (minutes)', 'read-aloud'), $this->number('debounce_minutes', (int) $s['debounce_minutes']));
        $out .= $this->row(__('Maximum wait (minutes)', 'read-aloud'), $this->number('max_wait_minutes', (int) $s['max_wait_minutes']));
        $out .= $this->row(__('Concurrency', 'read-aloud'), $this->number('concurrency', (int) $s['concurrency']));
        $out .= $this->row(__('Request timeout (seconds)', 'read-aloud'), $this->number('request_timeout', (int) $s['request_timeout']));
        $out .= $this->row(__('Chunk cache', 'read-aloud'), $this->checkbox('chunk_cache', (bool) $s['chunk_cache'], __('Keep synthesised chunks so a correction costs only the changed part.', 'read-aloud')));
        $out .= '</table>';

        $out .= '<h2>' . esc_html__('Player', 'read-aloud') . '</h2><table class="form-table" role="presentation">';
        $insert = '';
        foreach (['before' => __('Before the content', 'read-aloud'), 'after' => __('After the content', 'read-aloud'), 'none' => __('Do not insert', 'read-aloud')] as $value => $label) {
            $insert .= '<option value="' . esc_attr($value) . '"' . selected($s['insert'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        $out .= $this->row(__('Insert player', 'read-aloud'), '<select name="read_aloud[insert]">' . $insert . '</select>');
        $stale = '';
        foreach (['hide' => __('Hide the player until new audio is approved', 'read-aloud'), 'note' => __('Keep playing with a note', 'read-aloud')] as $value => $label) {
            $stale .= '<option value="' . esc_attr($value) . '"' . selected($s['stale_policy'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        $out .= $this->row(__('When the text changes', 'read-aloud'), '<select name="read_aloud[stale_policy]">' . $stale . '</select>');
        $out .= $this->row(__('Label', 'read-aloud'), $this->text('label', (string) $s['label'], __('Listen to this article', 'read-aloud')));
        $out .= $this->row(__('Disclosure', 'read-aloud'), $this->text('disclosure', (string) $s['disclosure'], __('Read by a synthetic voice.', 'read-aloud')) . '<p class="description">' . esc_html__('Empty uses the default line. The disclosure cannot be removed here.', 'read-aloud') . '</p>');
        $out .= $this->row(__('Speed buttons', 'read-aloud'), $this->checkbox('speed_buttons', (bool) $s['speed_buttons'], __('1x, 1.25x, 1.5x and 2x buttons (a small script).', 'read-aloud')));
        $out .= $this->row(__('Download link', 'read-aloud'), $this->checkbox('download_link', (bool) $s['download_link'], ''));
        $out .= $this->row(__('Feed enclosure', 'read-aloud'), $this->checkbox('feed_enclosure', (bool) $s['feed_enclosure'], __('Add approved audio to the RSS feed.', 'read-aloud')));
        $out .= $this->row(__('Check files exist', 'read-aloud'), $this->checkbox('check_file_exists', (bool) $s['check_file_exists'], __('One file check per page view. Off by default.', 'read-aloud')));
        $out .= $this->row(__('Grace period for old files (hours)', 'read-aloud'), $this->number('grace_hours', (int) $s['grace_hours']));
        $out .= $this->row(__('Keep audio files on uninstall', 'read-aloud'), $this->checkbox('keep_files', (bool) $s['keep_files'], ''));
        $out .= '</table>';
        $out .= get_submit_button(__('Save settings', 'read-aloud'));
        $out .= '</form>';

        $out .= '<h2>' . esc_html__('Tools', 'read-aloud') . '</h2>';
        $out .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:1em">'
            . '<input type="hidden" name="action" value="read_aloud_test_voice">' . wp_nonce_field('read_aloud_test_voice', '_wpnonce', true, false)
            . get_submit_button(__('Test voice', 'read-aloud'), 'secondary', 'submit', false, $guard->enabled() ? [] : ['disabled' => 'disabled'])
            . '<p class="description">' . esc_html__('Sends a fixed 100-character sample. It counts against the caps.', 'read-aloud') . '</p></form>';
        if ($guard->isHeld()) {
            $out .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block">'
                . '<input type="hidden" name="action" value="read_aloud_clear_hold">' . wp_nonce_field('read_aloud_clear_hold', '_wpnonce', true, false)
                . get_submit_button(__('Clear hold', 'read-aloud'), 'secondary', 'submit', false) . '</form>';
        }

        return $out . '</div>';
    }

    private function providerFields(string $providerId): string
    {
        $settings = $this->plugin->settings();
        try {
            $provider = $this->plugin->providers()->make($providerId, []);
        } catch (\Throwable $e) {
            return '';
        }
        $values = $settings->get('provider_settings');
        $values = is_array($values) && isset($values[$providerId]) && is_array($values[$providerId]) ? $values[$providerId] : [];
        $out = '<h3>' . esc_html($provider->label()) . '</h3><table class="form-table" role="presentation">';
        foreach ($provider->settingsFields() as $field) {
            $name = 'read_aloud[provider_fields][' . $field['key'] . ']';
            $value = isset($values[$field['key']]) && is_scalar($values[$field['key']]) ? (string) $values[$field['key']] : (string) ($field['default'] ?? '');
            if ($field['secret']) {
                $name = 'read_aloud[api_key]';
                $disabled = $settings->keyFromConstant();
                $html = '<input type="password" autocomplete="new-password" name="' . esc_attr($name) . '" value="" class="regular-text"' . ($disabled ? ' disabled' : '') . '>';
                if ($disabled) {
                    $html .= '<p class="description">' . esc_html__('The key comes from READ_ALOUD_API_KEY.', 'read-aloud') . '</p>';
                } elseif ($settings->hasSavedKey()) {
                    $html .= '<p class="description">' . esc_html__('A key is saved. Leave empty to keep it.', 'read-aloud') . '</p>';
                }
            } elseif ($field['type'] === 'textarea') {
                $html = '<textarea name="' . esc_attr($name) . '" rows="5" cols="60" class="code">' . esc_textarea($value) . '</textarea>';
            } elseif ($field['type'] === 'select') {
                $html = '<select name="' . esc_attr($name) . '">';
                foreach ($field['options'] ?? [] as $optionValue => $optionLabel) {
                    $html .= '<option value="' . esc_attr((string) $optionValue) . '"' . selected($value, (string) $optionValue, false) . '>' . esc_html($optionLabel) . '</option>';
                }
                $html .= '</select>';
            } else {
                $type = $field['type'] === 'url' ? 'url' : ($field['type'] === 'number' ? 'number' : 'text');
                $html = '<input type="' . $type . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="regular-text">';
            }
            if (!empty($field['help'])) {
                $html .= '<p class="description">' . esc_html($field['help']) . '</p>';
            }
            $out .= $this->row($field['label'], $html);
        }

        return $out . '</table>';
    }

    private function row(string $label, string $control): string
    {
        return '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $control . '</td></tr>';
    }

    private function checkbox(string $key, bool $checked, string $label): string
    {
        return '<label><input type="checkbox" name="read_aloud[' . esc_attr($key) . ']" value="1"' . checked($checked, true, false) . '> ' . esc_html($label) . '</label>';
    }

    private function number(string $key, int $value): string
    {
        return '<input type="number" name="read_aloud[' . esc_attr($key) . ']" value="' . esc_attr((string) $value) . '" class="small-text">';
    }

    private function text(string $key, string $value, string $placeholder = ''): string
    {
        return '<input type="text" name="read_aloud[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '" class="regular-text">';
    }
}
