<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud\Cli;

use IndianOpinion\ReadAloud\Data\AudioRecord;
use IndianOpinion\ReadAloud\Ledger\CapPolicy;
use IndianOpinion\ReadAloud\Plugin;
use IndianOpinion\ReadAloud\Review\Review;
use IndianOpinion\ReadAloud\Text\Hashes;

/**
 * Inspect and drive read-aloud from the terminal.
 *
 * Exit codes: 0 success, 1 error, 3 stopped by the kill switch, 4 stopped
 * by a cap, 5 account hold.
 */
final class Command
{
    public const EXIT_KILL = 3;
    public const EXIT_CAP = 4;
    public const EXIT_HOLD = 5;

    public function __construct(private readonly Plugin $plugin)
    {
    }

    /**
     * @param array<int|string, mixed> $assoc
     */
    private static function flag(array $assoc, string $name): bool
    {
        return isset($assoc[$name]) && $assoc[$name] !== false && $assoc[$name] !== 'false';
    }

    /**
     * @param list<string> $args
     * @return list<int>
     */
    private function postIds(array $args): array
    {
        $ids = [];
        foreach ($args as $arg) {
            if (!ctype_digit((string) $arg) || !get_post((int) $arg) instanceof \WP_Post) {
                \WP_CLI::error(sprintf('Not a post id: %s', (string) $arg));
            }
            $ids[] = (int) $arg;
        }

        return $ids;
    }

    /**
     * Show site status, or the records of the given posts.
     *
     * ## OPTIONS
     *
     * [<post>...]
     * : Post ids.
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $format = (string) ($assoc['format'] ?? 'table');
        if ($args === []) {
            $data = $this->plugin->statusData();
            $rows = [];
            foreach (['enabled', 'constant_disabled', 'held', 'queue_length', 'queued_chars', 'days_to_drain', 'saves_without_resynthesis'] as $key) {
                $value = $data[$key] ?? '';
                $rows[] = ['key' => $key, 'value' => is_bool($value) ? ($value ? 'yes' : 'no') : (is_scalar($value) ? (string) $value : (string) json_encode($value))];
            }
            foreach ((array) $data['today'] as $key => $value) {
                $rows[] = ['key' => 'today_' . $key, 'value' => is_scalar($value) ? (string) $value : (string) json_encode($value)];
            }
            foreach ((array) $data['counts'] as $status => $count) {
                $rows[] = ['key' => 'status_' . $status, 'value' => (string) $count];
            }
            \WP_CLI\Utils\format_items($format, $rows, ['key', 'value']);
            return;
        }
        $rows = [];
        foreach ($this->postIds($args) as $postId) {
            $record = new AudioRecord($this->plugin->records()->get($postId));
            $rows[] = [
                'post' => $postId,
                'status' => $record->status,
                'reason' => $record->reason,
                'file' => $record->fileKey,
                'pending' => $record->pending['file_key'] ?? '',
                'stale' => $record->isStale() ? 'yes' : 'no',
                'next_attempt' => $record->nextAttemptAt > 0 ? gmdate('c', $record->nextAttemptAt) : '',
                'error' => $record->lastErrorCode,
            ];
        }
        \WP_CLI\Utils\format_items($format, $rows, ['post', 'status', 'reason', 'file', 'pending', 'stale', 'next_attempt', 'error']);
    }

    /**
     * Queue posts for generation.
     *
     * ## OPTIONS
     *
     * <post>...
     * : Post ids.
     *
     * [--force]
     * : Queue even when the spoken text is unchanged.
     *
     * [--allow-over-cap]
     * : Run a post larger than the daily character cap.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function generate(array $args, array $assoc): void
    {
        foreach ($this->postIds($args) as $postId) {
            $result = $this->plugin->queue()->trigger($postId, 'cli', [
                'force' => self::flag($assoc, 'force'),
                'fresh' => self::flag($assoc, 'force'),
                'over_cap' => self::flag($assoc, 'allow-over-cap'),
            ]);
            \WP_CLI::log(sprintf('%d: %s', $postId, $result));
        }
        $this->haltIfStopped();
        \WP_CLI::success('Done.');
    }

    /**
     * Process the queue now.
     *
     * ## OPTIONS
     *
     * [--max=<n>]
     * : Most jobs to run.
     *
     * [--time=<seconds>]
     * : Stop starting new chunks after this many seconds.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function run(array $args, array $assoc): void
    {
        $max = isset($assoc['max']) ? max(0, (int) $assoc['max']) : 0;
        $deadline = isset($assoc['time']) ? $this->plugin->clock()->now() + max(1, (int) $assoc['time']) : null;
        $counts = $this->plugin->runner()->runDue($max, $deadline);
        foreach ($counts as $outcome => $count) {
            \WP_CLI::log(sprintf('%s: %d', $outcome, $count));
        }
        if (isset($counts['stopped_kill'])) {
            \WP_CLI::warning('Stopped: generation is turned off.');
            \WP_CLI::halt(self::EXIT_KILL);
        }
        if (isset($counts['held'])) {
            \WP_CLI::warning('Stopped: the site is on account hold.');
            \WP_CLI::halt(self::EXIT_HOLD);
        }
        if (isset($counts['capped'])) {
            \WP_CLI::warning('Stopped: a daily cap is reached.');
            \WP_CLI::halt(self::EXIT_CAP);
        }
        \WP_CLI::success('Queue processed.');
    }

    /**
     * Queue existing eligible posts that have no current approved file.
     *
     * ## OPTIONS
     *
     * [--category=<slug>]
     * : Only this category.
     *
     * [--after=<date>]
     * : Published on or after this date.
     *
     * [--before=<date>]
     * : Published on or before this date.
     *
     * [--limit=<n>]
     * : Most posts to queue.
     * ---
     * default: 50
     * ---
     *
     * [--outdated]
     * : Include records marked outdated_settings.
     *
     * [--dry-run]
     * : Print posts and estimated characters; queue nothing.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function backfill(array $args, array $assoc): void
    {
        $rows = $this->plugin->queue()->backfill([
            'category' => isset($assoc['category']) ? (string) $assoc['category'] : '',
            'after' => isset($assoc['after']) ? (string) $assoc['after'] : '',
            'before' => isset($assoc['before']) ? (string) $assoc['before'] : '',
            'limit' => isset($assoc['limit']) ? (int) $assoc['limit'] : 50,
            'outdated' => self::flag($assoc, 'outdated'),
            'dry_run' => self::flag($assoc, 'dry-run'),
        ]);
        \WP_CLI\Utils\format_items('table', $rows, ['post', 'chars', 'reason']);
        $total = array_sum(array_column($rows, 'chars'));
        \WP_CLI::log(sprintf('%d posts, about %d characters.', count($rows), $total));
        \WP_CLI::success(self::flag($assoc, 'dry-run') ? 'Dry run: nothing queued.' : 'Queued.');
    }

    /**
     * Remove queued backfill jobs. Running jobs finish their current chunk.
     *
     * @subcommand cancel-backfill
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function cancel_backfill(array $args, array $assoc): void
    {
        \WP_CLI::success(sprintf('%d backfill jobs removed.', $this->plugin->queue()->cancelBackfill()));
    }

    /**
     * @param array<string, mixed> $assoc
     */
    private function reviewUser(array $assoc): int
    {
        $userId = isset($assoc['user']) ? (int) $assoc['user'] : 0;
        if ($userId <= 0 || get_userdata($userId) === false) {
            \WP_CLI::error('--user=<id> is required and must be an existing user.');
        }

        return $userId;
    }

    /**
     * Approve pending audio.
     *
     * ## OPTIONS
     *
     * <post>...
     * : Post ids.
     *
     * --user=<id>
     * : The user who listened and approves.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function approve(array $args, array $assoc): void
    {
        $this->review($args, $assoc, true);
    }

    /**
     * Reject pending audio.
     *
     * ## OPTIONS
     *
     * <post>...
     * : Post ids.
     *
     * --user=<id>
     * : The user who rejects.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function reject(array $args, array $assoc): void
    {
        $this->review($args, $assoc, false);
    }

    /**
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    private function review(array $args, array $assoc, bool $approve): void
    {
        $userId = $this->reviewUser($assoc);
        $failed = 0;
        foreach ($this->postIds($args) as $postId) {
            if (!Review::canReview($postId, $userId)) {
                \WP_CLI::warning(sprintf('%d: user %d cannot edit this post.', $postId, $userId));
                $failed++;
                continue;
            }
            $result = $approve ? $this->plugin->review()->approve($postId, $userId) : $this->plugin->review()->reject($postId, $userId);
            if ($result instanceof \WP_Error) {
                \WP_CLI::warning(sprintf('%d: %s', $postId, $result->get_error_message()));
                $failed++;
                continue;
            }
            \WP_CLI::log(sprintf('%d: %s', $postId, $approve ? 'approved' : 'rejected'));
        }
        if ($failed > 0) {
            \WP_CLI::error(sprintf('%d posts not changed.', $failed));
        }
        \WP_CLI::success('Done.');
    }

    /**
     * Delete the audio of posts and turn audio off for them.
     *
     * ## OPTIONS
     *
     * <post>...
     * : Post ids.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function remove(array $args, array $assoc): void
    {
        foreach ($this->postIds($args) as $postId) {
            $this->plugin->review()->remove($postId);
            \WP_CLI::log(sprintf('%d: removed', $postId));
        }
        \WP_CLI::success('Done.');
    }

    /**
     * Turn generation off (the kill switch setting).
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function pause(array $args, array $assoc): void
    {
        $this->plugin->guard()->setEnabled(false, 'wp-cli');
        \WP_CLI::success('Generation is off. Approved audio keeps playing.');
    }

    /**
     * Turn generation back on.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function resume(array $args, array $assoc): void
    {
        $this->plugin->guard()->setEnabled(true, 'wp-cli');
        if ($this->plugin->guard()->constantDisabled()) {
            \WP_CLI::warning('READ_ALOUD_DISABLED is defined, so generation stays off.');
            \WP_CLI::halt(self::EXIT_KILL);
        }
        $this->plugin->queue()->redispatchWaiting();
        \WP_CLI::success('Generation is on.');
    }

    /**
     * Clear the account hold.
     *
     * @subcommand clear-hold
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function clear_hold(array $args, array $assoc): void
    {
        if ($this->plugin->guard()->clearHold('wp-cli')) {
            $this->plugin->queue()->redispatchWaiting();
            \WP_CLI::success('Hold cleared.');
            return;
        }
        \WP_CLI::success('There was no hold.');
    }

    /**
     * Daily counters.
     *
     * ## OPTIONS
     *
     * [--days=<n>]
     * : How many days.
     * ---
     * default: 7
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function usage(array $args, array $assoc): void
    {
        $days = max(1, min(30, (int) ($assoc['days'] ?? 7)));
        $rows = [];
        foreach ($this->plugin->ledger()->history($days, $this->plugin->days()->today()) as $row) {
            $rows[] = [
                'day' => $row['day'],
                'chars' => $row['chars'],
                'generations' => $row['generations'],
                'money' => CapPolicy::fromMicros($row['money']),
                'reserved_chars' => $row['reserved_chars'],
                'saves_skipped' => $row['saves_skipped'],
            ];
        }
        \WP_CLI\Utils\format_items((string) ($assoc['format'] ?? 'table'), $rows, ['day', 'chars', 'generations', 'money', 'reserved_chars', 'saves_skipped']);
    }

    /**
     * Check that every approved file exists.
     *
     * ## OPTIONS
     *
     * [--requeue]
     * : Queue posts whose file is missing.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function verify(array $args, array $assoc): void
    {
        $missing = $this->plugin->maintenance()->verify(null, self::flag($assoc, 'requeue'));
        foreach ($missing as $postId) {
            \WP_CLI::log(sprintf('%d: missing', $postId));
        }
        \WP_CLI::success(sprintf('%d missing files.', count($missing)));
    }

    /**
     * Remove files in the audio directory that no record points to.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : List them only.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function prune(array $args, array $assoc): void
    {
        $dry = self::flag($assoc, 'dry-run');
        $keys = $this->plugin->maintenance()->prune($dry);
        foreach ($keys as $key) {
            \WP_CLI::log($key);
        }
        \WP_CLI::success(sprintf('%d orphan files %s.', count($keys), $dry ? 'found' : 'deleted'));
    }

    /**
     * Print the spoken text and hashes of a post. Makes no call.
     *
     * ## OPTIONS
     *
     * <post>
     * : Post id.
     *
     * @param list<string> $args
     * @param array<string, mixed> $assoc
     */
    public function text(array $args, array $assoc): void
    {
        $postId = $this->postIds(array_slice($args, 0, 1))[0];
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            \WP_CLI::error('No such post.');
            return;
        }
        $plan = $this->plugin->planner()->plan($post);
        $text = $plan->text !== '' ? $plan->text : $this->plugin->planner()->spokenText($post);
        \WP_CLI::log($text);
        \WP_CLI::log('');
        \WP_CLI::log('characters: ' . mb_strlen($text, 'UTF-8'));
        \WP_CLI::log('content hash: ' . ($plan->contentHash !== '' ? $plan->contentHash : Hashes::content($text)));
        \WP_CLI::log('render hash: ' . $plan->renderHash);
        \WP_CLI::log('eligible: ' . ($plan->eligible ? 'yes' : 'no (' . $plan->reason . ')'));
    }

    private function haltIfStopped(): void
    {
        if (!$this->plugin->guard()->enabled()) {
            \WP_CLI::warning('Queued, but generation is turned off.');
            \WP_CLI::halt(self::EXIT_KILL);
        }
        if ($this->plugin->guard()->isHeld()) {
            \WP_CLI::warning('Queued, but the site is on account hold.');
            \WP_CLI::halt(self::EXIT_HOLD);
        }
    }
}
