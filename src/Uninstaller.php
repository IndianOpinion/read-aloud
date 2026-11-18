<?php

declare(strict_types=1);

namespace IndianOpinion\ReadAloud;

use IndianOpinion\ReadAloud\Control\Guard;
use IndianOpinion\ReadAloud\Data\Records;
use IndianOpinion\ReadAloud\Ledger\Ledger;
use IndianOpinion\ReadAloud\Ledger\WpdbLedger;
use IndianOpinion\ReadAloud\Settings\Settings;
use IndianOpinion\ReadAloud\Storage\UploadsStore;
use IndianOpinion\ReadAloud\Support\EventLog;

/**
 * Removes everything the plugin created: options, post meta, scheduled
 * events, tables and, unless asked to keep them, the audio files. On
 * multisite it runs for every site.
 */
final class Uninstaller
{
    public const OPTIONS = [
        Settings::OPTION,
        Settings::OPTION_KEY,
        Guard::OPTION_ENABLED,
        Guard::OPTION_HOLD,
        EventLog::OPTION,
        Plugin::DB_VERSION_OPTION,
        Maintenance::OPTION_CURSOR,
        Maintenance::OPTION_NORMALISER,
    ];

    public const META = [Records::META, Records::META_MODE, Records::META_STATUS, Records::META_DUE, Records::META_LIVE];

    /**
     * @param (callable(): Ledger)|null $ledgerFactory
     */
    public static function run(?callable $ledgerFactory = null): void
    {
        if (is_multisite()) {
            $sites = get_sites(['fields' => 'ids', 'number' => 0]);
            foreach (is_array($sites) ? $sites : [] as $siteId) {
                switch_to_blog((int) $siteId);
                self::site($ledgerFactory);
                restore_current_blog();
            }
            return;
        }
        self::site($ledgerFactory);
    }

    /**
     * @param (callable(): Ledger)|null $ledgerFactory
     */
    public static function site(?callable $ledgerFactory = null): void
    {
        $settings = get_option(Settings::OPTION, []);
        $keep = (is_array($settings) && !empty($settings['keep_files']))
            || (defined('READ_ALOUD_KEEP_FILES') && (bool) constant('READ_ALOUD_KEEP_FILES'));

        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }
        foreach (self::META as $key) {
            delete_post_meta_by_key($key);
        }
        Plugin::deactivate();

        if ($ledgerFactory !== null) {
            $ledger = $ledgerFactory();
        } else {
            global $wpdb;
            $ledger = new WpdbLedger($wpdb);
        }
        $ledger->uninstall();

        $outside = Plugin::outsideWebRoot();
        if ($outside !== null) {
            self::removeTree($outside);
        }
        if ($keep) {
            return;
        }
        $uploads = wp_upload_dir(null, false);
        self::removeTree(rtrim((string) $uploads['basedir'], '/') . '/' . UploadsStore::DIR);
    }

    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }
}
