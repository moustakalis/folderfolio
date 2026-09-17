<?php

declare(strict_types=1);

namespace FolderFolio;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Admin\FolderSelect;
use FolderFolio\Admin\FoldersColumn;
use FolderFolio\Admin\ImportPage;
use FolderFolio\Admin\MediaLibraryFilter;
use FolderFolio\Admin\MediaLibraryIntegration;
use FolderFolio\Admin\MediaModalIntegration;
use FolderFolio\Admin\Menu;
use FolderFolio\Admin\Rail;
use FolderFolio\Admin\SettingsPage;
use FolderFolio\Database\Schema;
use FolderFolio\Domain\AttachmentFolderRepository;
use FolderFolio\Cli\FolderCommand;
use FolderFolio\Cli\RootCommand;
use FolderFolio\Rest\FolderController;
use FolderFolio\Rest\PreferenceController;
use FolderFolio\Support\UploadRouter;
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
    public const DB_VERSION = '4';

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

        $this->registerCliCommands();
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
        add_action('delete_attachment', [$this, 'forgetAttachment']);

        // Not admin-only: its clause filter also has to cover REST media
        // queries and anything else that sets the folder query var.
        (new MediaLibraryFilter())->register();

        // Does nothing until something uses the
        // folderfolio_default_folder_for_upload filter.
        (new UploadRouter())->register();

        if (is_admin()) {
            // The rail owns the shell — where it mounts, its width, whether it
            // is open. MediaLibraryIntegration still enqueues and populates
            // the tree that goes inside it.
            (new Rail())->register();
            (new MediaLibraryIntegration())->register();

            // The folder select in list mode's filter bar. Printed by PHP so
            // that it filters the library with scripts off, and so that a list
            // refresh gets a correctly-selected copy back from the server.
            (new FolderSelect())->register();

            // The Folders column in the list table — the only column
            // FolderFolio adds, and where list mode's drill-down happens.
            (new FoldersColumn())->register();

            // One instance each. Menu places the screen and hands
            // SettingsPage its hook suffix; SettingsPage owns the three tabs
            // and asks ImportPage for the middle one.
            $import = new ImportPage();
            $settings = new SettingsPage($import);

            (new Menu($settings))->register();
            $settings->register();
            $import->register();

            // The folder column inside the media picker — every screen that
            // can open one except upload.php, which has the rail. Rewritten at
            // step 9: the version that patched wp.media.create globally is
            // gone, and what is left touches one view method, additively.
            (new MediaModalIntegration())->register();
        }
    }

    /**
     * WP-CLI commands, when running under WP-CLI.
     *
     * Registered here rather than in the bootstrap so the autoloader and the
     * public facade are both already loaded.
     */
    private function registerCliCommands(): void
    {
        if (!defined('WP_CLI') || !constant('WP_CLI')) {
            return;
        }

        \WP_CLI::add_command('folderfolio', RootCommand::class);
        \WP_CLI::add_command('folderfolio folder', FolderCommand::class);
    }

    public function loadTextDomain(): void
    {
        load_plugin_textdomain(
            'folderfolio',
            false,
            dirname(plugin_basename(FOLDERFOLIO_PLUGIN_FILE)) . '/languages'
        );
    }

    /**
     * Drop folder assignments for media that no longer exists.
     *
     * @param int $attachmentId
     */
    public function forgetAttachment($attachmentId): void
    {
        (new AttachmentFolderRepository())->deleteForAttachment((int) $attachmentId);
    }

    public function registerRestRoutes(): void
    {
        (new FolderController())->registerRoutes();
        (new ImportController())->registerRoutes();
        (new PreferenceController())->registerRoutes();
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
