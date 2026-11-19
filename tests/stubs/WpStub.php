<?php

/**
 * In-memory state behind the stub functions. Reset before every test.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

final class WpStub
{
    public static int $blog = 1;
    public static bool $multisite = false;
    /** @var list<int> */
    public static array $sites = [1];
    /** @var array<int, array<string, mixed>> */
    public static array $options = [];
    /** @var array<int, array<int, array<string, mixed>>> */
    public static array $meta = [];
    /** @var array<int, WP_Post> */
    public static array $posts = [];
    /** @var array<int, list<int>> post id => term ids */
    public static array $postTerms = [];
    /** @var array<int, WP_Term> */
    public static array $terms = [];
    /** @var array<string, array<int, list<callable>>> */
    public static array $filters = [];
    /** @var list<array{0: int, 1: string, 2: array<int, mixed>}> */
    public static array $cron = [];
    /** @var list<array{url: string, args: array<string, mixed>, safe: bool}> */
    public static array $http = [];
    /** @var list<callable|array<string, mixed>> */
    public static array $httpQueue = [];
    /** @var list<string> Hosts that resolve to private addresses. */
    public static array $privateHosts = [];
    /** @var array<string, list<string>> Host to addresses for the endpoint guard. */
    public static array $dns = [];
    public static int $currentUser = 0;
    /** @var array<int, array<string, bool|list<int>>> */
    public static array $caps = [];
    /** @var array<string, mixed> */
    public static array $transients = [];
    /** @var list<string> */
    public static array $enqueued = [];
    /** @var list<int> */
    public static array $cleaned = [];
    /** @var array<string, array<string, mixed>> */
    public static array $routes = [];
    /** @var list<string> */
    public static array $cacheDeleted = [];
    public static ?int $currentPost = null;
    public static string $locale = 'en_GB';
    public static string $uploadsDir = '';
    /** @var list<int> */
    public static array $autosaves = [];
    /** @var array<string, bool> */
    public static array $conditionals = [];
    /** @var array<string, array<string, mixed>> */
    public static array $blockTypes = [];
    public static int $nextId = 100;

    public static function reset(string $uploadsDir): void
    {
        self::$blog = 1;
        self::$multisite = false;
        self::$sites = [1];
        self::$options = [];
        self::$meta = [];
        self::$posts = [];
        self::$postTerms = [];
        self::$terms = [];
        self::$filters = [];
        self::$cron = [];
        self::$http = [];
        self::$httpQueue = [];
        self::$privateHosts = [];
        self::$dns = [];
        self::$currentUser = 0;
        self::$caps = [];
        self::$transients = [];
        self::$enqueued = [];
        self::$cleaned = [];
        self::$routes = [];
        self::$cacheDeleted = [];
        self::$currentPost = null;
        self::$locale = 'en_GB';
        self::$uploadsDir = $uploadsDir;
        self::$autosaves = [];
        self::$conditionals = ['is_feed' => false, 'in_the_loop' => true, 'is_main_query' => true, 'is_singular' => true, 'is_admin' => false];
        self::$blockTypes = [];
        self::$nextId = 100;
    }

    /** @return array<int, array<string, mixed>> */
    public static function &meta(): array
    {
        if (!isset(self::$meta[self::$blog])) {
            self::$meta[self::$blog] = [];
        }

        return self::$meta[self::$blog];
    }

    /** @param array<string, mixed> $fields */
    public static function post(array $fields = [], array $categories = []): WP_Post
    {
        $id = $fields['ID'] ?? self::$nextId++;
        $post = new WP_Post(['ID' => $id] + $fields + ['post_title' => 'Post ' . $id]);
        self::$posts[$id] = $post;
        self::$postTerms[$id] = $categories;

        return $post;
    }

    public static function term(int $id, string $name, int $parent = 0): WP_Term
    {
        return self::$terms[$id] = new WP_Term(['term_id' => $id, 'name' => $name, 'slug' => strtolower($name), 'parent' => $parent]);
    }

    /** Queue an HTTP response: array or callable(url, args): array|WP_Error. */
    public static function respond(callable|array $response): void
    {
        self::$httpQueue[] = $response;
    }

    /** @return list<array{0: int, 1: string, 2: array<int, mixed>}> */
    public static function cronFor(string $hook): array
    {
        return array_values(array_filter(self::$cron, static fn (array $e): bool => $e[1] === $hook));
    }

    public static function can(int $userId, array $caps): void
    {
        self::$caps[$userId] = $caps;
    }
}
