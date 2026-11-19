<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud;

use IndianOpinion\ReadAloud\Admin\EditorPanel;
use IndianOpinion\ReadAloud\Admin\Notices;
use IndianOpinion\ReadAloud\Admin\PostsList;
use IndianOpinion\ReadAloud\Admin\SettingsPage;
use IndianOpinion\ReadAloud\Admin\StatusPage;
use IndianOpinion\ReadAloud\Cli\Command;
use IndianOpinion\ReadAloud\Clock\Clock;
use IndianOpinion\ReadAloud\Clock\SystemClock;
use IndianOpinion\ReadAloud\Control\Guard;
use IndianOpinion\ReadAloud\Data\AudioRecord;
use IndianOpinion\ReadAloud\Data\Records;
use IndianOpinion\ReadAloud\Data\Status;
use IndianOpinion\ReadAloud\Front\Player;
use IndianOpinion\ReadAloud\Integrations\GenQueue;
use IndianOpinion\ReadAloud\Integrations\SpendMeter;
use IndianOpinion\ReadAloud\Jobs\Dispatcher;
use IndianOpinion\ReadAloud\Jobs\Generator;
use IndianOpinion\ReadAloud\Jobs\Planner;
use IndianOpinion\ReadAloud\Jobs\Queue;
use IndianOpinion\ReadAloud\Jobs\Runner;
use IndianOpinion\ReadAloud\Ledger\CapPolicy;
use IndianOpinion\ReadAloud\Ledger\Ledger;
use IndianOpinion\ReadAloud\Ledger\WpdbLedger;
use IndianOpinion\ReadAloud\Provider\ProviderRegistry;
use IndianOpinion\ReadAloud\Rest\Routes;
use IndianOpinion\ReadAloud\Review\Review;
use IndianOpinion\ReadAloud\Settings\Settings;
use IndianOpinion\ReadAloud\Storage\AudioStore;
use IndianOpinion\ReadAloud\Storage\ChunkCache;
use IndianOpinion\ReadAloud\Storage\UploadsStore;
use IndianOpinion\ReadAloud\Support\Days;
use IndianOpinion\ReadAloud\Support\EventLog;

/**
 * Service container and hook wiring. Services are built lazily so a page
 * view only builds what the player needs.
 */
final class Plugin
{
    public const VERSION = '0.1.0';
    public const DB_VERSION_OPTION = 'read_aloud_db_version';

    private static ?Plugin $instance = null;

    private ?Settings $settings = null;
    private ?Records $records = null;
    private ?Planner $planner = null;
    private ?EventLog $log = null;
    private ?Guard $guard = null;
    private ?Days $days = null;
    private ?Dispatcher $dispatcher = null;
    private ?Queue $queue = null;
    private ?Generator $generator = null;
    private ?Runner $runner = null;
    private ?Review $review = null;
    private ?Player $player = null;
    private ?Maintenance $maintenance = null;
    private ?CapPolicy $capPolicy = null;
    private ?ProviderRegistry $providers = null;
    private ?ChunkCache $cache;
    private ?AudioStore $store;
    private ?Ledger $ledger;
    private Clock $clock;

    /** @var array<int, string> Post ids waiting for an end-of-request trigger. */
    private array $deferred = [];

    public function __construct(?Clock $clock = null, ?Ledger $ledger = null, ?AudioStore $store = null, ?ChunkCache $cache = null)
    {
        $this->clock = $clock ?? new SystemClock();
        $this->ledger = $ledger;
        $this->store = $store;
        $this->cache = $cache;
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public static function setInstance(?self $plugin): void
    {
        self::$instance = $plugin;
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    public function providers(): ProviderRegistry
    {
        return $this->providers ??= new ProviderRegistry();
    }

    public function settings(): Settings
    {
        return $this->settings ??= new Settings($this->providers());
    }

    public function records(): Records
    {
        return $this->records ??= new Records();
    }

    public function planner(): Planner
    {
        return $this->planner ??= new Planner($this->settings(), $this->records());
    }

    public function log(): EventLog
    {
        if ($this->log === null) {
            $this->log = new EventLog($this->clock);
            $this->log->addSecret($this->settings()->apiKey());
        }

        return $this->log;
    }

    public function guard(): Guard
    {
        return $this->guard ??= new Guard($this->clock, $this->log());
    }

    public function days(): Days
    {
        return $this->days ??= new Days($this->clock);
    }

    public function ledger(): Ledger
    {
        if ($this->ledger === null) {
            global $wpdb;
            $this->ledger = new WpdbLedger($wpdb);
        }

        return $this->ledger;
    }

    public function store(): AudioStore
    {
        if ($this->store === null) {
            $custom = apply_filters('read_aloud_store', null);
            $this->store = $custom instanceof AudioStore ? $custom : new UploadsStore();
        }

        return $this->store;
    }

    public function cache(): ChunkCache
    {
        if ($this->cache === null) {
            $uploads = wp_upload_dir(null, false);
            $uploadsDir = rtrim((string) $uploads['basedir'], '/') . '/' . UploadsStore::DIR;
            $override = defined('READ_ALOUD_CHUNK_DIR') ? (string) constant('READ_ALOUD_CHUNK_DIR') : null;
            $override = apply_filters('read_aloud_chunk_dir', $override);
            if (is_string($override) && $override !== '' && is_multisite()) {
                // Post ids repeat across sites: each site gets its own folder.
                $override = rtrim($override, '/') . '/site-' . get_current_blog_id();
            }
            $this->cache = ChunkCache::locate(
                $this->settings()->bool('chunk_cache'),
                is_string($override) ? $override : null,
                self::outsideWebRoot(),
                $uploadsDir
            );
        }

        return $this->cache;
    }

    /**
     * A directory beside the WordPress root, used only when WordPress is
     * installed at the site root (so its parent is not web-served).
     */
    public static function outsideWebRoot(): ?string
    {
        if (!defined('ABSPATH')) {
            return null;
        }
        $home = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        $site = (string) wp_parse_url(site_url('/'), PHP_URL_PATH);
        if (rtrim($home, '/') !== '' || rtrim($site, '/') !== '') {
            return null;
        }
        $dir = dirname(rtrim((string) constant('ABSPATH'), '/')) . '/read-aloud-chunks';
        if (is_multisite()) {
            $dir .= '/site-' . get_current_blog_id();
        }

        return $dir;
    }

    public function capPolicy(): CapPolicy
    {
        return $this->capPolicy ??= new CapPolicy($this->settings(), $this->ledger(), $this->days());
    }

    public function dispatcher(): Dispatcher
    {
        return $this->dispatcher ??= new Dispatcher(new GenQueue(), $this->ledger(), $this->days(), $this->log(), $this->capPolicy());
    }

    public function queue(): Queue
    {
        return $this->queue ??= new Queue(
            $this->settings(),
            $this->records(),
            $this->planner(),
            $this->dispatcher(),
            $this->ledger(),
            $this->days(),
            $this->clock,
            $this->log()
        );
    }

    public function generator(): Generator
    {
        return $this->generator ??= new Generator(
            $this->settings(),
            $this->records(),
            $this->planner(),
            $this->queue(),
            $this->dispatcher(),
            $this->providers(),
            $this->store(),
            $this->cache(),
            $this->ledger(),
            $this->capPolicy(),
            $this->guard(),
            new SpendMeter(),
            $this->days(),
            $this->clock,
            $this->log()
        );
    }

    public function runner(): Runner
    {
        return $this->runner ??= new Runner(
            $this->records(),
            $this->generator(),
            $this->dispatcher(),
            $this->ledger(),
            $this->settings(),
            $this->guard(),
            $this->clock,
            $this->log()
        );
    }

    public function review(): Review
    {
        return $this->review ??= new Review(
            $this->settings(),
            $this->records(),
            $this->queue(),
            $this->store(),
            $this->cache(),
            $this->clock,
            $this->log()
        );
    }

    public function player(): Player
    {
        return $this->player ??= new Player($this->settings(), $this->records(), $this->planner(), $this->store());
    }

    public function maintenance(): Maintenance
    {
        return $this->maintenance ??= new Maintenance(
            $this->records(),
            $this->planner(),
            $this->queue(),
            $this->review(),
            $this->store(),
            $this->cache(),
            $this->ledger(),
            $this->days(),
            $this->clock,
            $this->log()
        );
    }

    /** Wire every hook. Called once from the plugin file. */
    public function register(string $pluginFile): void
    {
        register_activation_hook($pluginFile, [$this, 'activate']);
        register_deactivation_hook($pluginFile, [self::class, 'deactivate']);

        add_action('init', [$this, 'init']);
        add_action('wp_after_insert_post', [$this, 'onAfterInsert'], 20, 4);
        add_action('set_object_terms', [$this, 'onTerms'], 20, 6);
        add_action('trashed_post', [$this, 'onStatusHook']);
        add_action('untrashed_post', [$this, 'onStatusHook']);
        add_action('before_delete_post', [$this, 'onDelete']);
        add_action('shutdown', [$this, 'flushDeferred']);

        add_action(Dispatcher::CRON_HOOK, [$this, 'onJob']);
        add_action(Runner::SWEEP_HOOK, [$this, 'onSweep']);
        add_action(Maintenance::DAILY_HOOK, [$this, 'onDaily']);
        add_action(Maintenance::OUTDATED_HOOK, [$this, 'onMarkOutdated']);
        add_action('gen_queue_register_types', [$this, 'registerQueueType']);

        add_filter('the_content', [$this, 'onContent'], 20);
        add_action('rss2_item', [$this, 'onFeedItem']);
        add_action('rest_api_init', [$this, 'onRestInit']);

        if (is_admin()) {
            (new SettingsPage($this))->register();
            (new StatusPage($this))->register();
            (new EditorPanel($this))->register();
            (new PostsList($this))->register();
            (new Notices($this))->register();
        }
        if (defined('WP_CLI') && WP_CLI && class_exists(\WP_CLI::class)) {
            \WP_CLI::add_command('read-aloud', new Command($this));
        }
    }

    public function activate(): void
    {
        $this->install();
        if (get_option(Guard::OPTION_ENABLED, null) === null) {
            add_option(Guard::OPTION_ENABLED, '1', '', false);
        }
        self::scheduleRecurring();
    }

    public function install(): void
    {
        $this->ledger()->install();
        update_option(self::DB_VERSION_OPTION, (string) WpdbLedger::SCHEMA_VERSION, false);
    }

    public static function scheduleRecurring(): void
    {
        if (wp_next_scheduled(Maintenance::DAILY_HOOK) === false) {
            wp_schedule_event(time() + 3600, 'daily', Maintenance::DAILY_HOOK);
        }
        if (wp_next_scheduled(Runner::SWEEP_HOOK) === false) {
            wp_schedule_event(time() + 600, 'hourly', Runner::SWEEP_HOOK);
        }
    }

    /** Deactivation only clears scheduled events. */
    public static function deactivate(): void
    {
        foreach ([Maintenance::DAILY_HOOK, Runner::SWEEP_HOOK, Maintenance::OUTDATED_HOOK] as $hook) {
            wp_clear_scheduled_hook($hook);
        }
        wp_unschedule_hook(Dispatcher::CRON_HOOK);
    }

    public function init(): void
    {
        if (get_option(self::DB_VERSION_OPTION) !== (string) WpdbLedger::SCHEMA_VERSION) {
            $this->install();
        }
        self::scheduleRecurring();
        load_plugin_textdomain('read-aloud', false, dirname(plugin_basename(READ_ALOUD_FILE)) . '/languages');
        wp_register_script(Player::SCRIPT, plugins_url('assets/player-speed.js', READ_ALOUD_FILE), [], self::VERSION, ['in_footer' => true, 'strategy' => 'defer']);
        wp_register_script('read-aloud-editor', plugins_url('assets/editor.js', READ_ALOUD_FILE), ['wp-plugins', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-blocks', 'wp-i18n'], self::VERSION, true);
        register_block_type(Player::BLOCK, [
            'api_version' => '3',
            'title' => __('Read aloud player', 'read-aloud'),
            'category' => 'media',
            'render_callback' => [$this->player(), 'renderBlock'],
            'uses_context' => ['postId'],
            'editor_script' => 'read-aloud-editor',
            'supports' => ['html' => false, 'multiple' => false],
        ]);
    }

    /** Queue a trigger for the end of the request, once per post. */
    public function defer(int $postId, string $reason): void
    {
        $current = $this->deferred[$postId] ?? null;
        // A publish beats an update, which beats terms and status.
        $rank = ['publish' => 3, 'update' => 2, 'setting' => 1, 'terms' => 1, 'status' => 0];
        if ($current === null || ($rank[$reason] ?? 0) > ($rank[$current] ?? 0)) {
            $this->deferred[$postId] = $reason;
        }
    }

    /** @return array<int, string> Outcome per post id. */
    public function flushDeferred(): array
    {
        $out = [];
        $deferred = $this->deferred;
        $this->deferred = [];
        foreach ($deferred as $postId => $reason) {
            $out[$postId] = $this->queue()->trigger($postId, $reason);
        }

        return $out;
    }

    public function onAfterInsert(int $postId, \WP_Post $post, bool $update, ?\WP_Post $before = null): void
    {
        if (wp_is_post_revision($postId) !== false || wp_is_post_autosave($postId) !== false) {
            return;
        }
        if ($post->post_status !== 'publish' && ($before === null || $before->post_status !== 'publish') && !$this->records()->exists($postId)) {
            return;
        }
        $reason = $before instanceof \WP_Post && $before->post_status === 'publish' ? 'update' : 'publish';
        $this->defer($postId, $post->post_status === 'publish' ? $reason : 'status');
    }

    /**
     * @param array<int, int|string> $terms
     * @param array<int, int> $ttIds
     * @param array<int, int> $oldTtIds
     */
    public function onTerms(int $objectId, array $terms, array $ttIds, string $taxonomy, bool $append, array $oldTtIds): void
    {
        if ($taxonomy !== 'category') {
            return;
        }
        $post = get_post($objectId);
        if ($post instanceof \WP_Post && ($post->post_status === 'publish' || $this->records()->exists($objectId))) {
            $this->defer($objectId, 'terms');
        }
    }

    public function onStatusHook(int $postId): void
    {
        if ($this->records()->exists($postId)) {
            $this->defer($postId, 'status');
            $this->review()->playerChanged($postId);
        }
    }

    public function onDelete(int $postId): void
    {
        $this->review()->purge($postId);
    }

    public function onJob(int $postId): void
    {
        $this->runner()->handle($postId);
    }

    public function onSweep(): void
    {
        $this->runner()->sweep();
    }

    public function onDaily(): void
    {
        $this->maintenance()->daily();
    }

    public function onMarkOutdated(int $offset = 0): void
    {
        $this->maintenance()->markOutdated($offset);
    }

    /** Register the generation job type with gen-queue when it is active. */
    public function registerQueueType(): void
    {
        GenQueue::registerType(fn (int $postId): string => $this->runner()->handle($postId));
    }

    /**
     * @param mixed $content
     * @return mixed
     */
    public function onContent($content)
    {
        return is_string($content) ? $this->player()->insert($content) : $content;
    }

    public function onFeedItem(): void
    {
        $this->player()->enclosure();
    }

    public function onRestInit(): void
    {
        (new Routes($this))->register();
    }

    /**
     * Everything the editor panel and the REST route show an editor.
     *
     * @return array<string, mixed>
     */
    public function editorState(\WP_Post $post): array
    {
        $postId = (int) $post->ID;
        $record = new AudioRecord($this->records()->get($postId));
        $plan = $this->planner()->plan($post);
        $pending = $record->pending;

        return [
            'post' => $postId,
            'mode' => $this->records()->mode($postId),
            'inherit_resolves' => $this->planner()->categoryOptedIn($post) ? 'on' : 'off',
            'eligible' => $plan->eligible,
            'eligibility_reason' => $plan->reason,
            'status' => $record->status,
            'reason' => $record->reason,
            'estimated_chars' => $plan->chars,
            'spoken_text' => $plan->text,
            'live' => $record->hasLiveFile() ? [
                'url' => $record->fileUrl,
                'mime' => $record->mime,
                'duration' => $record->duration,
                'approved_at' => $record->approvedAt,
                'stale' => $record->isStale(),
                'outdated' => $record->outdated,
            ] : null,
            'pending' => $pending !== null ? [
                'url' => (string) ($pending['file_url'] ?? ''),
                'mime' => (string) ($pending['mime'] ?? ''),
                'duration' => (float) ($pending['duration'] ?? 0),
                'chars' => (int) ($pending['chars'] ?? 0),
                'generated_at' => (int) ($pending['generated_at'] ?? 0),
                'matches_current_text' => ($pending['content_hash'] ?? '') === $plan->contentHash,
            ] : null,
            'next_attempt_at' => in_array($record->status, [Status::RETRYING, Status::CAPPED, Status::QUEUED], true) ? $record->nextAttemptAt : 0,
            'last_error_code' => $record->lastErrorCode,
            'last_error_message' => $record->lastErrorMessage,
            'enabled' => $this->guard()->enabled(),
            'held' => $this->guard()->isHeld(),
        ];
    }

    /**
     * Data for the status screen, the REST status route and the CLI.
     *
     * @return array<string, mixed>
     */
    public function statusData(): array
    {
        $today = $this->days()->today();
        $usage = $this->ledger()->usage($today);
        $caps = $this->capPolicy()->caps();
        $queued = 0;
        $queueLength = 0;
        foreach ($this->records()->waitingIds() as $postId) {
            $record = $this->records()->get($postId);
            if (is_array($record['job'])) {
                $queueLength++;
                $queued += (int) ($record['job']['chars'] ?? 0);
            }
        }
        $history = $this->ledger()->history(30, $today);

        return [
            'enabled' => $this->guard()->enabled(),
            'setting_enabled' => $this->guard()->settingEnabled(),
            'constant_disabled' => $this->guard()->constantDisabled(),
            'held' => $this->guard()->hold(),
            'today' => [
                'day' => $today,
                'chars' => $usage['chars'],
                'reserved_chars' => $usage['reserved_chars'],
                'generations' => $usage['generations'],
                'money' => CapPolicy::fromMicros($usage['money']),
                'saves_skipped' => $usage['saves_skipped'],
            ],
            'caps' => [
                'chars' => $caps->chars,
                'generations' => $caps->generations,
                'money' => $caps->moneyMicros === null ? null : CapPolicy::fromMicros($caps->moneyMicros),
            ],
            'history' => $history,
            'queue_length' => $queueLength,
            'queued_chars' => $queued,
            'days_to_drain' => $caps->chars > 0 ? (int) ceil($queued / $caps->chars) : null,
            'counts' => $this->records()->countByStatus(),
            'saves_without_resynthesis' => array_sum(array_column($history, 'saves_skipped')),
            'events' => $this->log()->recent(50),
        ];
    }
}
