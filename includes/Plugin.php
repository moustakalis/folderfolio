<?php

declare(strict_types=1);

namespace FolderFolio;

use FolderFolio\Admin\ImportPage;
use FolderFolio\Admin\MediaLibraryFilter;
use FolderFolio\Admin\MediaLibraryIntegration;
use FolderFolio\Database\Schema;
use FolderFolio\Rest\FolderController;
use FolderFolio\Rest\ImportController;

/**
 * Plugin bootstrap.
 *
 * Asset loading is owned by the Admin integrations, not by this class: each
 * screen knows which bundles it needs.
 */
final class Plugin
{
    /**
     * Bumped whenever Schema::migrate() changes, to trigger an upgrade run.
     */
    public const DB_VERSION = '1';

    private const DB_VERSION_OPTION = 'folderfolio_db_version';

    private const UPGRADE_LOCK = 'folderfolio_upgrading';

    /**
     * Entry point, hooked to plugins_loaded by the plugin bootstrap file.
     */
    public static function init(): void
    {
        (new self())->boot();
    }

    /**
     * Runs once on activation, via register_activation_hook().
     */
    public static function activate(): void
    {
        (new Schema())->migrate();

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    public static function deactivate(): void
    {
        // Folder metadata is deliberately retained on deactivation.
    }

    public function boot(): void
    {
        // Not on plugins_loaded: that would run dbDelta on the first front-end
        // request after an update, for whoever happens to arrive first.
        add_action('admin_init', [$this, 'maybeUpgradeSchema']);

        add_action('init', [$this, 'loadTextDomain']);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);

        if (is_admin()) {
            (new MediaLibraryFilter())->register();
            (new MediaLibraryIntegration())->register();
            (new ImportPage())->register();

            // MediaModalIntegration is deliberately not registered yet: it
            // patches wp.media.create globally, which can break other plugins'
            // media frames. It is re-enabled once the modal phase reworks it
            // onto a supported extension point.
        }
    }

    public function loadTextDomain(): void
    {
        load_plugin_textdomain(
            'folderfolio',
            false,
            dirname(plugin_basename(FOLDERFOLIO_PLUGIN_FILE)) . '/languages'
        );
    }

    public function registerRestRoutes(): void
    {
        (new FolderController())->registerRoutes();
        (new ImportController())->registerRoutes();
    }

    /**
     * Apply schema changes after a plugin update, where the activation hook
     * does not run again.
     */
    public function maybeUpgradeSchema(): void
    {
        if (get_option(self::DB_VERSION_OPTION) === self::DB_VERSION) {
            return;
        }

        // Concurrent admin requests would otherwise each start their own
        // migration. Whoever sets the lock runs it; everyone else waits for the
        // next request.
        if (!$this->acquireUpgradeLock()) {
            return;
        }

        (new Schema())->migrate();

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);

        delete_transient(self::UPGRADE_LOCK);
    }

    private function acquireUpgradeLock(): bool
    {
        if (get_transient(self::UPGRADE_LOCK)) {
            return false;
        }

        set_transient(self::UPGRADE_LOCK, time(), 5 * MINUTE_IN_SECONDS);

        return true;
    }
}
