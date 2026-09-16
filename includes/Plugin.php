<?php

declare(strict_types=1);

namespace FolderFolio;

use FolderFolio\Admin\ImportPage;
use FolderFolio\Admin\MediaLibraryIntegration;
use FolderFolio\Admin\MediaModalIntegration;
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
        $this->maybeUpgradeSchema();

        add_action('init', [$this, 'loadTextDomain']);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);

        if (is_admin()) {
            (new MediaLibraryIntegration())->register();
            (new MediaModalIntegration())->register();
            (new ImportPage())->register();
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
    private function maybeUpgradeSchema(): void
    {
        if (get_option(self::DB_VERSION_OPTION) === self::DB_VERSION) {
            return;
        }

        (new Schema())->migrate();

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }
}
