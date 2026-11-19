<?php

/**
 * Minimal stand-ins for the WordPress and WP-CLI classes the plugin uses.
 * Enough for unit tests; not a WordPress implementation.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Classes.ClassDeclaration.MissingNamespace

class WP_Post
{
    public int $ID = 0;
    public string $post_type = 'post';
    public string $post_status = 'publish';
    public string $post_password = '';
    public string $post_title = '';
    public string $post_content = '';
    public string $post_date = '2026-11-01 10:00:00';
    public string $post_date_gmt = '2026-11-01 10:00:00';
    public string $post_modified_gmt = '2026-11-01 10:00:00';
    public int $post_parent = 0;
    public string $post_name = '';

    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(array $fields = [])
    {
        foreach ($fields as $key => $value) {
            $this->{$key} = $value;
        }
    }
}

class WP_Term
{
    public int $term_id = 0;
    public string $name = '';
    public string $slug = '';
    public int $parent = 0;
    public string $taxonomy = 'category';

    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(array $fields = [])
    {
        foreach ($fields as $key => $value) {
            $this->{$key} = $value;
        }
    }
}

class WP_Post_Type
{
    public function __construct(public string $name, public string $label)
    {
    }
}

class WP_Error
{
    /** @var array<string, mixed> */
    public array $data;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(public string $code = '', public string $message = '', mixed $data = [])
    {
        $this->data = is_array($data) ? $data : [];
    }

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    public function get_error_data(): mixed
    {
        return $this->data;
    }
}

class WP_REST_Request
{
    /** @var array<string, mixed> */
    private array $params = [];
    /** @var array<string, string> */
    private array $headers = [];

    public function __construct(public string $method = 'GET', public string $route = '')
    {
    }

    public function set_param(string $key, mixed $value): void
    {
        $this->params[$key] = $value;
    }

    public function get_param(string $key): mixed
    {
        return $this->params[$key] ?? null;
    }

    public function set_header(string $key, string $value): void
    {
        $this->headers[strtolower(str_replace('-', '_', $key))] = $value;
    }

    public function get_header(string $key): ?string
    {
        return $this->headers[strtolower(str_replace('-', '_', $key))] ?? null;
    }
}

class WP_REST_Response
{
    public function __construct(public mixed $data = null, public int $status = 200)
    {
    }

    public function get_data(): mixed
    {
        return $this->data;
    }
}

class WP_Block
{
    /** @param array<string, mixed> $context */
    public function __construct(public array $context = [])
    {
    }
}

/**
 * The query surface the plugin uses, with a prepare() that understands
 * %i, %s and %d. Subclasses provide query execution.
 */
class wpdb
{
    public string $prefix = 'wp_';
    public int $rows_affected = 0;
    public string $options = 'wp_options';

    public function get_charset_collate(): string
    {
        return '';
    }

    public function prepare(string $query, mixed ...$args): string
    {
        $i = 0;
        return (string) preg_replace_callback('/%([isd])/', function (array $m) use (&$i, $args): string {
            $value = $args[$i++] ?? null;
            if ($m[1] === 'd') {
                return (string) (int) $value;
            }
            if ($m[1] === 'i') {
                return '`' . str_replace('`', '``', (string) $value) . '`';
            }
            return "'" . addslashes((string) $value) . "'";
        }, $query);
    }

    public function query(string $query): int|bool
    {
        return 0;
    }

    public function get_row(?string $query = null, string $output = 'OBJECT'): mixed
    {
        return null;
    }

    /** @return list<mixed> */
    public function get_col(?string $query = null): array
    {
        return [];
    }

    public function get_results(?string $query = null, string $output = 'OBJECT'): mixed
    {
        return [];
    }
}

class WpDieException extends RuntimeException
{
}

class WpCliExit extends RuntimeException
{
}

class WP_CLI
{
    /** @var list<string> */
    public static array $lines = [];
    /** @var list<array<string, mixed>> */
    public static array $items = [];
    /** @var array<string, object> */
    public static array $commands = [];

    public static function reset(): void
    {
        self::$lines = [];
        self::$items = [];
    }

    public static function add_command(string $name, object $command): void
    {
        self::$commands[$name] = $command;
    }

    public static function log(string $message): void
    {
        self::$lines[] = $message;
    }

    public static function success(string $message): void
    {
        self::$lines[] = 'Success: ' . $message;
    }

    public static function warning(string $message): void
    {
        self::$lines[] = 'Warning: ' . $message;
    }

    /** @return never */
    public static function error(string $message): void
    {
        self::$lines[] = 'Error: ' . $message;
        throw new WpCliExit($message, 1);
    }

    /** @return never */
    public static function halt(int $code): void
    {
        throw new WpCliExit('halt', $code);
    }
}
