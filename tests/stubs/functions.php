<?php

/**
 * Stub WordPress functions over WpStub state.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects, Squiz.Functions.GlobalFunction

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

// Hooks.
function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    WpStub::$filters[$hook][$priority][] = $callback;
    ksort(WpStub::$filters[$hook]);
    return true;
}
function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $callback, $priority, $args);
}
function has_filter(string $hook): bool
{
    return !empty(WpStub::$filters[$hook]);
}
function has_action(string $hook): bool
{
    return has_filter($hook);
}
function remove_action(string $hook, callable $callback, int $priority = 10): bool
{
    foreach (WpStub::$filters[$hook][$priority] ?? [] as $i => $registered) {
        if ($registered === $callback) {
            unset(WpStub::$filters[$hook][$priority][$i]);
            if (WpStub::$filters[$hook][$priority] === []) {
                unset(WpStub::$filters[$hook][$priority]);
            }
            if (WpStub::$filters[$hook] === []) {
                unset(WpStub::$filters[$hook]);
            }
            return true;
        }
    }
    return false;
}
function remove_all_filters(string $hook): void
{
    unset(WpStub::$filters[$hook]);
}
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    foreach (WpStub::$filters[$hook] ?? [] as $callbacks) {
        foreach ($callbacks as $callback) {
            $value = $callback($value, ...$args);
        }
    }
    return $value;
}
function do_action(string $hook, mixed ...$args): void
{
    foreach (WpStub::$filters[$hook] ?? [] as $callbacks) {
        foreach ($callbacks as $callback) {
            $callback(...$args);
        }
    }
}

// Options and transients.
function get_option(string $name, mixed $default = false): mixed
{
    return array_key_exists($name, WpStub::$options[WpStub::$blog] ?? []) ? WpStub::$options[WpStub::$blog][$name] : $default;
}
function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    WpStub::$options[WpStub::$blog][$name] = $value;
    return true;
}
function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = null): bool
{
    if (array_key_exists($name, WpStub::$options[WpStub::$blog] ?? [])) {
        return false;
    }
    WpStub::$options[WpStub::$blog][$name] = $value;
    return true;
}
function delete_option(string $name): bool
{
    unset(WpStub::$options[WpStub::$blog][$name]);
    return true;
}
function wp_cache_delete(int|string $key, string $group = ''): bool
{
    WpStub::$cacheDeleted[] = $group . ':' . $key;
    return true;
}
function set_transient(string $name, mixed $value, int $expiration = 0): bool
{
    WpStub::$transients[$name] = $value;
    return true;
}
function get_transient(string $name): mixed
{
    return WpStub::$transients[$name] ?? false;
}
function delete_transient(string $name): bool
{
    unset(WpStub::$transients[$name]);
    return true;
}

// Posts, meta and terms.
function get_post(int|WP_Post|null $post = null): ?WP_Post
{
    if ($post instanceof WP_Post) {
        return $post;
    }
    $id = $post ?? WpStub::$currentPost;
    return $id !== null && isset(WpStub::$posts[$id]) ? WpStub::$posts[$id] : null;
}
function get_the_ID(): int|false
{
    return WpStub::$currentPost ?? false;
}
function wp_is_post_revision(int|WP_Post $post): int|false
{
    $post = get_post($post);
    return $post !== null && $post->post_type === 'revision' ? max(1, $post->post_parent) : false;
}
function wp_is_post_autosave(int|WP_Post $post): int|false
{
    $post = get_post($post);
    return $post !== null && in_array($post->ID, WpStub::$autosaves, true) ? max(1, $post->post_parent) : false;
}
function get_post_meta(int $postId, string $key = '', bool $single = false): mixed
{
    $meta = &WpStub::meta();
    if (!isset($meta[$postId][$key])) {
        return $single ? '' : [];
    }
    return $single ? $meta[$postId][$key] : [$meta[$postId][$key]];
}
function update_post_meta(int $postId, string $key, mixed $value): bool
{
    $meta = &WpStub::meta();
    $meta[$postId][$key] = is_string($value) ? wp_unslash($value) : $value;
    return true;
}
function delete_post_meta(int $postId, string $key): bool
{
    $meta = &WpStub::meta();
    unset($meta[$postId][$key]);
    return true;
}
function delete_post_meta_by_key(string $key): bool
{
    $meta = &WpStub::meta();
    foreach ($meta as $postId => $values) {
        unset($meta[$postId][$key]);
    }
    return true;
}
function clean_post_cache(int|WP_Post $post): void
{
    WpStub::$cleaned[] = $post instanceof WP_Post ? $post->ID : $post;
}
/** @return list<WP_Term>|false */
function get_the_terms(int|WP_Post $post, string $taxonomy): array|false
{
    $id = $post instanceof WP_Post ? $post->ID : $post;
    $out = [];
    foreach (WpStub::$postTerms[$id] ?? [] as $termId) {
        if (isset(WpStub::$terms[$termId])) {
            $out[] = WpStub::$terms[$termId];
        }
    }
    return $out === [] ? false : $out;
}
/** @return list<int> */
function get_ancestors(int $id, string $object = '', string $type = ''): array
{
    $out = [];
    $term = WpStub::$terms[$id] ?? null;
    while ($term !== null && $term->parent > 0) {
        $out[] = $term->parent;
        $term = WpStub::$terms[$term->parent] ?? null;
    }
    return $out;
}
/** @return list<WP_Term> */
function get_categories(array $args = []): array
{
    return array_values(WpStub::$terms);
}
/** @return array<string, WP_Post_Type> */
function get_post_types(array $args = [], string $output = 'names'): array
{
    return ['post' => new WP_Post_Type('post', 'Posts'), 'page' => new WP_Post_Type('page', 'Pages')];
}
function post_type_exists(string $type): bool
{
    return in_array($type, ['post', 'page'], true);
}
function has_block(string $name, int|WP_Post|null $post = null): bool
{
    $post = get_post($post);
    return $post !== null && str_contains($post->post_content, '<!-- wp:' . $name);
}
/**
 * @param array<string, mixed> $args
 * @return list<int|WP_Post>
 */
function get_posts(array $args = []): array
{
    $meta = WpStub::meta();
    $types = $args['post_type'] ?? 'post';
    $statuses = $args['post_status'] ?? 'publish';
    $out = [];
    foreach (WpStub::$posts as $id => $post) {
        if ($types !== 'any' && !in_array($post->post_type, (array) $types, true)) {
            continue;
        }
        if ($statuses !== 'any' && !in_array($post->post_status, (array) $statuses, true)) {
            continue;
        }
        if (isset($args['has_password']) && $args['has_password'] === false && $post->post_password !== '') {
            continue;
        }
        if (!empty($args['category_name'])) {
            $slugs = array_map(static fn (int $t): string => WpStub::$terms[$t]->slug ?? '', WpStub::$postTerms[$id] ?? []);
            if (!in_array($args['category_name'], $slugs, true)) {
                continue;
            }
        }
        foreach ($args['date_query'] ?? [] as $clause) {
            if (!empty($clause['after']) && strtotime($post->post_date) < strtotime((string) $clause['after'])) {
                continue 2;
            }
            if (!empty($clause['before']) && strtotime($post->post_date) > strtotime((string) $clause['before'] . ' 23:59:59')) {
                continue 2;
            }
        }
        foreach ($args['meta_query'] ?? [] as $clause) {
            $has = isset($meta[$id][$clause['key']]);
            $value = $has ? $meta[$id][$clause['key']] : null;
            $compare = $clause['compare'] ?? '=';
            $ok = match ($compare) {
                'EXISTS' => $has,
                'IN' => $has && in_array($value, (array) $clause['value'], true),
                '<=' => $has && (float) $value <= (float) $clause['value'],
                default => $has && $value == $clause['value'],
            };
            if (!$ok) {
                continue 2;
            }
        }
        $out[$id] = $post;
    }
    if (($args['orderby'] ?? '') === 'meta_value_num' && isset($args['meta_key'])) {
        uksort($out, static fn (int $a, int $b): int => ((float) ($meta[$a][$args['meta_key']] ?? 0) <=> (float) ($meta[$b][$args['meta_key']] ?? 0)) ?: $a <=> $b);
    } elseif (($args['orderby'] ?? '') === 'date') {
        uasort($out, static fn (WP_Post $a, WP_Post $b): int => strcmp($b->post_date, $a->post_date) ?: $b->ID <=> $a->ID);
    } else {
        ksort($out);
    }
    $out = array_values($out);
    $offset = (int) ($args['offset'] ?? 0);
    $limit = (int) ($args['posts_per_page'] ?? 10);
    $out = array_slice($out, $offset, $limit < 0 ? null : $limit);
    if (($args['fields'] ?? '') === 'ids') {
        return array_map(static fn (WP_Post $p): int => $p->ID, $out);
    }
    return $out;
}

// Users, capabilities and nonces.
function get_current_user_id(): int
{
    return WpStub::$currentUser;
}
function user_can(int $userId, string $capability, mixed ...$args): bool
{
    $caps = WpStub::$caps[$userId] ?? [];
    if (!isset($caps[$capability])) {
        return false;
    }
    if (is_array($caps[$capability])) {
        return isset($args[0]) && in_array((int) $args[0], $caps[$capability], true);
    }
    return (bool) $caps[$capability];
}
function current_user_can(string $capability, mixed ...$args): bool
{
    return user_can(WpStub::$currentUser, $capability, ...$args);
}
function get_userdata(int $userId): object|false
{
    return isset(WpStub::$caps[$userId]) ? (object) ['ID' => $userId] : false;
}
function wp_create_nonce(string $action = ''): string
{
    return 'nonce-' . $action . '-' . WpStub::$currentUser;
}
function wp_verify_nonce(string $nonce, string $action = ''): int|false
{
    return $nonce === wp_create_nonce($action) ? 1 : false;
}
function check_admin_referer(string $action = '', string $query = '_wpnonce'): int
{
    $nonce = isset($_REQUEST[$query]) && is_string($_REQUEST[$query]) ? $_REQUEST[$query] : '';
    if (wp_verify_nonce($nonce, $action) === false) {
        throw new WpDieException('nonce');
    }
    return 1;
}
function wp_nonce_field(string $action = '', string $name = '_wpnonce', bool $referer = true, bool $display = true): string
{
    return '<input type="hidden" name="' . $name . '" value="' . wp_create_nonce($action) . '">';
}
function wp_die(string $message = '', mixed $title = '', mixed $args = []): never
{
    throw new WpDieException($message);
}

// Cron.
function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
{
    WpStub::$cron[] = [$timestamp, $hook, $args];
    return true;
}
function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
{
    WpStub::$cron[] = [$timestamp, $hook, $args];
    return true;
}
function wp_next_scheduled(string $hook, array $args = []): int|false
{
    foreach (WpStub::$cron as $event) {
        if ($event[1] === $hook && $event[2] == $args) {
            return $event[0];
        }
    }
    return false;
}
function wp_clear_scheduled_hook(string $hook, array $args = []): int
{
    $before = count(WpStub::$cron);
    WpStub::$cron = array_values(array_filter(WpStub::$cron, static fn (array $e): bool => !($e[1] === $hook && $e[2] == $args)));
    return $before - count(WpStub::$cron);
}
function wp_unschedule_hook(string $hook): int
{
    $before = count(WpStub::$cron);
    WpStub::$cron = array_values(array_filter(WpStub::$cron, static fn (array $e): bool => $e[1] !== $hook));
    return $before - count(WpStub::$cron);
}

// HTTP.
function wp_http_validate_url(string $url): string|false
{
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
        return false;
    }
    $host = strtolower($parts['host']);
    if ($host === 'localhost' || in_array($host, WpStub::$privateHosts, true)) {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP) !== false && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return false;
    }
    return $url;
}
/** @return array<string, mixed>|WP_Error */
function stub_http(string $url, array $args, bool $safe): array|WP_Error
{
    WpStub::$http[] = ['url' => $url, 'args' => $args, 'safe' => $safe];
    if ($safe && wp_http_validate_url($url) === false) {
        return new WP_Error('http_request_failed', 'A valid URL was not provided.');
    }
    $next = array_shift(WpStub::$httpQueue);
    if ($next === null) {
        return new WP_Error('http_request_failed', 'No stubbed response.');
    }
    $response = is_callable($next) ? $next($url, $args) : $next;
    if ($response instanceof WP_Error) {
        return $response;
    }
    $response += ['headers' => [], 'body' => '', 'response' => ['code' => 200]];
    $response['headers'] = array_change_key_case($response['headers'], CASE_LOWER);
    if (!empty($args['stream']) && !empty($args['filename'])) {
        file_put_contents($args['filename'], $response['body']);
        $response['body'] = '';
    }
    return $response;
}
function wp_safe_remote_post(string $url, array $args = []): array|WP_Error
{
    return stub_http($url, $args, true);
}
function wp_remote_post(string $url, array $args = []): array|WP_Error
{
    return stub_http($url, $args, false);
}
function wp_remote_retrieve_response_code(array|WP_Error $response): int|string
{
    return is_array($response) ? (int) ($response['response']['code'] ?? 0) : '';
}
function wp_remote_retrieve_header(array|WP_Error $response, string $header): array|string
{
    return is_array($response) ? ($response['headers'][strtolower($header)] ?? '') : '';
}

// Escaping, sanitising and i18n.
function esc_html(mixed $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}
function esc_attr(mixed $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}
function esc_textarea(mixed $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}
function esc_url_raw(string $url): string
{
    return preg_match('~^(https?:)?//~i', $url) === 1 || str_starts_with($url, '/') ? $url : '';
}
function esc_url(string $url): string
{
    return htmlspecialchars(esc_url_raw($url), ENT_QUOTES, 'UTF-8');
}
function __(string $text, string $domain = 'default'): string
{
    return $text;
}
function esc_html__(string $text, string $domain = 'default'): string
{
    return esc_html($text);
}
function sanitize_text_field(string $text): string
{
    return trim((string) preg_replace('/[\r\n\t ]+/', ' ', strip_tags($text)));
}
function sanitize_key(string $key): string
{
    return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
}
function wp_slash(mixed $value): mixed
{
    return is_string($value) ? addslashes($value) : $value;
}
function wp_unslash(mixed $value): mixed
{
    if (is_array($value)) {
        return array_map('wp_unslash', $value);
    }
    return is_string($value) ? stripslashes($value) : $value;
}
function selected(mixed $a, mixed $b = true, bool $display = true): string
{
    return (string) $a === (string) $b ? ' selected="selected"' : '';
}
function checked(mixed $a, mixed $b = true, bool $display = true): string
{
    return (string) $a === (string) $b ? ' checked="checked"' : '';
}
function get_submit_button(string $text = 'Save', string $type = 'primary', string $name = 'submit', bool $wrap = true, array|string $other = []): string
{
    return '<input type="submit" name="' . esc_attr($name) . '" value="' . esc_attr($text) . '">';
}
function wp_date(string $format, ?int $timestamp = null): string
{
    return gmdate($format, $timestamp ?? time());
}
function wp_timezone(): DateTimeZone
{
    $name = get_option('timezone_string', 'UTC');
    return new DateTimeZone(is_string($name) && $name !== '' ? $name : 'UTC');
}
function get_locale(): string
{
    return WpStub::$locale;
}

// URLs, paths and environment.
function home_url(string $path = ''): string
{
    return 'https://example.test' . $path;
}
function site_url(string $path = ''): string
{
    return 'https://example.test' . $path;
}
function admin_url(string $path = ''): string
{
    return 'https://example.test/wp-admin/' . $path;
}
function rest_url(string $path = ''): string
{
    return 'https://example.test/wp-json/' . $path;
}
function plugins_url(string $path = '', string $plugin = ''): string
{
    return 'https://example.test/wp-content/plugins/read-aloud/' . $path;
}
function plugin_basename(string $file): string
{
    return 'read-aloud/' . basename($file);
}
function wp_parse_url(string $url, int $component = -1): mixed
{
    return parse_url($url, $component);
}
function add_query_arg(array $args, string $url = ''): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
}
/** @return array<string, string|false> */
function wp_upload_dir(?string $time = null, bool $create = true): array
{
    return ['basedir' => WpStub::$uploadsDir, 'baseurl' => 'https://example.test/wp-content/uploads', 'error' => false];
}
function wp_mkdir_p(string $dir): bool
{
    if (is_dir($dir)) {
        return true;
    }
    // WordPress silences mkdir here; the stub does the same without @.
    set_error_handler(static fn (): bool => true);
    try {
        return mkdir($dir, 0775, true);
    } finally {
        restore_error_handler();
    }
}
function get_temp_dir(): string
{
    return sys_get_temp_dir() . '/';
}
function is_multisite(): bool
{
    return WpStub::$multisite;
}
function get_current_blog_id(): int
{
    return WpStub::$blog;
}
/** @return list<int> */
function get_sites(array $args = []): array
{
    return WpStub::$sites;
}
function switch_to_blog(int $id): bool
{
    WpStub::$blog = $id;
    return true;
}
function restore_current_blog(): bool
{
    WpStub::$blog = 1;
    return true;
}
function is_admin(): bool
{
    return WpStub::$conditionals['is_admin'] ?? false;
}
function is_feed(): bool
{
    return WpStub::$conditionals['is_feed'] ?? false;
}
function in_the_loop(): bool
{
    return WpStub::$conditionals['in_the_loop'] ?? true;
}
function is_main_query(): bool
{
    return WpStub::$conditionals['is_main_query'] ?? true;
}
function is_singular(): bool
{
    return WpStub::$conditionals['is_singular'] ?? true;
}

// Registration, assets and admin screens (recorded, not rendered).
function wp_register_script(string $handle, string $src, array $deps = [], mixed $ver = false, mixed $args = []): bool
{
    return true;
}
function wp_enqueue_script(string $handle, string $src = '', array $deps = [], mixed $ver = false, mixed $args = []): void
{
    WpStub::$enqueued[] = $handle;
}
function wp_localize_script(string $handle, string $name, array $data): bool
{
    return true;
}
function register_block_type(string $name, array $args = []): bool
{
    WpStub::$blockTypes[$name] = $args;
    return true;
}
function register_rest_route(string $namespace, string $route, array $args = []): bool
{
    WpStub::$routes[$namespace . $route] = $args;
    return true;
}
function register_activation_hook(string $file, callable $callback): void
{
}
function register_deactivation_hook(string $file, callable $callback): void
{
}
function load_plugin_textdomain(string $domain, mixed $deprecated = false, string $path = ''): bool
{
    return true;
}
function add_meta_box(string $id, string $title, callable $callback, mixed $screen = null, string $context = 'advanced', string $priority = 'default', ?array $args = null): void
{
}
function add_options_page(string $a, string $b, string $cap, string $slug, callable $callback): string
{
    return $slug;
}
function add_management_page(string $a, string $b, string $cap, string $slug, callable $callback): string
{
    return $slug;
}
function wp_safe_redirect(string $location, int $status = 302): bool
{
    return true;
}

// Blocks: a small parser compatible with the shape parse_blocks() returns.
/** @return list<array<string, mixed>> */
function parse_blocks(string $content): array
{
    $pattern = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';
    $root = ['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []];
    $stack = [];
    $output = [];
    $offset = 0;
    $freeform = static function (string $html) use (&$output): void {
        if (trim($html) !== '') {
            $output[] = ['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => $html, 'innerContent' => [$html]];
        }
    };
    while (preg_match($pattern, $content, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $start = $m[0][1];
        $length = strlen($m[0][0]);
        $html = substr($content, $offset, $start - $offset);
        $name = ($m['namespace'][0] !== '' && $m['namespace'][1] !== -1 ? $m['namespace'][0] : 'core/') . $m['name'][0];
        $attrs = isset($m['attrs']) && $m['attrs'][1] !== -1 && $m['attrs'][0] !== '' ? json_decode(trim($m['attrs'][0]), true) : [];
        $isCloser = isset($m['closer']) && $m['closer'][1] !== -1 && $m['closer'][0] !== '';
        $isVoid = isset($m['void']) && $m['void'][1] !== -1 && $m['void'][0] !== '';
        if ($stack === []) {
            $freeform($html);
        } else {
            $top = count($stack) - 1;
            $stack[$top]['innerHTML'] .= $html;
            $stack[$top]['innerContent'][] = $html;
        }
        $block = ['blockName' => $name, 'attrs' => is_array($attrs) ? $attrs : [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []];
        if ($isVoid) {
            if ($stack === []) {
                $output[] = $block;
            } else {
                $top = count($stack) - 1;
                $stack[$top]['innerBlocks'][] = $block;
                $stack[$top]['innerContent'][] = null;
            }
        } elseif ($isCloser) {
            $done = array_pop($stack);
            if ($done !== null) {
                if ($stack === []) {
                    $output[] = $done;
                } else {
                    $top = count($stack) - 1;
                    $stack[$top]['innerBlocks'][] = $done;
                    $stack[$top]['innerContent'][] = null;
                }
            }
        } else {
            $stack[] = $block;
        }
        $offset = $start + $length;
    }
    $freeform(substr($content, $offset));
    unset($root);
    return $output;
}
