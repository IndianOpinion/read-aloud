<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Support;

use IndianOpinion\ReadAloud\Clock\Clock;

/**
 * The last 200 events, in a non-autoloaded option. Never holds post text
 * or keys. Each event is also written to the PHP error log when
 * WP_DEBUG_LOG is on.
 */
final class EventLog
{
    public const OPTION = 'read_aloud_events';
    public const KEEP = 200;

    /** @var list<string> */
    private array $secrets = [];

    public function __construct(private readonly Clock $clock)
    {
    }

    /** Values that must never be written, such as the saved key. */
    public function addSecret(string $secret): void
    {
        if ($secret !== '') {
            $this->secrets[] = $secret;
        }
    }

    /**
     * @param array<string, scalar|null> $data
     */
    public function add(string $type, int $postId = 0, array $data = []): void
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = Redactor::clean($value, $this->secrets, 300);
            }
        }
        $event = ['t' => $this->clock->now(), 'type' => $type, 'post' => $postId, 'data' => $data];
        $events = get_option(self::OPTION, []);
        $events = is_array($events) ? array_values($events) : [];
        $events[] = $event;
        if (count($events) > self::KEEP) {
            $events = array_slice($events, -self::KEEP);
        }
        update_option(self::OPTION, $events, false);
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $parts = [];
            foreach ($data as $key => $value) {
                $parts[] = $key . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            }
            error_log('[read-aloud] ' . $type . ($postId > 0 ? ' post=' . $postId : '') . ($parts === [] ? '' : ' ' . implode(' ', $parts)));
        }
    }

    /**
     * @return list<array{t: int, type: string, post: int, data: array<string, scalar|null>}>
     */
    public function recent(int $limit = 50): array
    {
        $events = get_option(self::OPTION, []);
        if (!is_array($events)) {
            return [];
        }
        $out = [];
        foreach (array_reverse($events) as $event) {
            if (is_array($event) && isset($event['type'])) {
                $out[] = [
                    't' => (int) ($event['t'] ?? 0),
                    'type' => (string) $event['type'],
                    'post' => (int) ($event['post'] ?? 0),
                    'data' => is_array($event['data'] ?? null) ? $event['data'] : [],
                ];
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
