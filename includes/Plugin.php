<?php

declare(strict_types=1);

namespace FolderFolio;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Admin\FolderDownload;
use FolderFolio\Admin\FolderSelect;
use FolderFolio\Admin\FoldersColumn;
use FolderFolio\Admin\PostFolders;
use FolderFolio\Admin\ImportPage;
use FolderFolio\Admin\MediaLibraryFilter;
use FolderFolio\Admin\StartupFolder;
use FolderFolio\Admin\MediaLibraryIntegration;
use FolderFolio\Admin\MediaModalIntegration;
use FolderFolio\Admin\Menu;
use FolderFolio\Admin\PluginsRow;
use FolderFolio\Admin\Rail;
use FolderFolio\Admin\SettingsPage;
use FolderFolio\Blocks\Gallery;
use FolderFolio\Database\Network;
use FolderFolio\Database\Schema;
use FolderFolio\Domain\AttachmentFolderRepository;
use FolderFolio\Cli\FolderCommand;
use FolderFolio\Cli\ImportCommand;
use FolderFolio\Cli\RootCommand;
use FolderFolio\Cli\SettingsCommand;
use FolderFolio\Cli\SmartCommand;
use FolderFolio\Rest\FolderController;
use FolderFolio\Rest\PreferenceController;
use FolderFolio\Rest\SettingsController;
use FolderFolio\Rest\SmartController;
use FolderFolio\Support\FileSizes;
use FolderFolio\Support\UploadRouter;
use FolderFolio\Support\UploadTarget;
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
    public const DB_VERSION = '7';

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
     *
     * WordPress fires the hook once, on the site it is called from, even when
     * the plugin is activated for a whole network — so a network activation
     * installs every site here (review item #27). Sites made later are
     * installed by `Database\Network` on `wp_initialize_site`.
     *
     * A large network (over 10,000 sites, core's own line) is not looped: one
     * request cannot create forty thousand tables. Each of those sites gets
     * its tables from `maybeUpgradeSchema()` on its first admin load instead,
     * which is the same path a plugin update takes.
     *
     * @param bool|mixed $networkWide
     */
    public static function activate($networkWide = false): void
    {
        if ($networkWide && is_multisite()) {
            if (wp_is_large_network('sites')) {
                return;
            }

            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                self::install();
                restore_current_blog();
            }

            return;
        }

        self::install();
    }

    /**
     * This site's tables and schema version. Idempotent: dbDelta compares.
     */
    public static function install(): void
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

        // A site added to a network the plugin is active on, and a site
        // deleted from one — review item #27.
        if (is_multisite()) {
            (new Network())->register();
        }

        // No load_plugin_textdomain(): since WordPress 4.6 core loads a
        // wordpress.org plugin's translations itself, from the language packs
        // translate.wordpress.org builds from this source. languages/ carries
        // the POT for anyone translating outside it.

        $this->registerCliCommands();
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
        add_action('delete_attachment', [$this, 'forgetAttachment']);
        add_action('deleted_post', [$this, 'forgetPost'], 10, 2);

        // Not admin-only: its clause filter also has to cover REST media
        // queries and anything else that sets the folder query var.
        (new MediaLibraryFilter())->register();

        // Each file's size as a number SQL can compare — a smart folder's
        // size rule (tier 3 item 13). Not admin-only: an upload over REST or
        // WP-CLI writes attachment metadata too.
        (new FileSizes())->register();

        // Does nothing until something uses the
        // folderfolio_default_folder_for_upload filter.
        (new UploadRouter())->register();

        // Answers UploadRouter's filter with the folder the request named, so
        // the two are registered together and neither is useful alone.
        (new UploadTarget())->register();

        // Not admin-only, and it matters: a block registered only in the
        // admin renders on the front end as "this block contains unexpected
        // or invalid content".
        (new Gallery())->register();

        if (is_admin()) {
            // The rail owns the shell — where it mounts, its width, whether it
            // is open. MediaLibraryIntegration still enqueues and populates
            // the tree that goes inside it.
            (new Rail())->register();
            (new MediaLibraryIntegration())->register();

            // Sends a bare arrival at upload.php to the same URL with the
            // startup folder on it. Registered beside the rail because the
            // two are one feature: this puts the folder in the URL, the rail
            // is what says so on the screen.
            (new StartupFolder())->register();

            // Download a folder as a ZIP (tier 2 item 11): admin-post.php is
            // an admin request, so this is where its handler belongs.
            (new FolderDownload())->register();

            // The folder select in list mode's filter bar. Printed by PHP so
            // that it filters the library with scripts off, and so that a list
            // refresh gets a correctly-selected copy back from the server.
            (new FolderSelect())->register();

            // A post's folders from the editor, and Add New from inside a
            // folder filing the new post there — tier 3 item 12.
            (new PostFolders())->register();

            // One line under our own row on the plugins screen, when another
            // folder plugin holds data and this library holds none of ours.
            // It is the moment of activation; the rail's empty state is the
            // moment of confusion.
            (new PluginsRow())->register();

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
        \WP_CLI::add_command('folderfolio import', ImportCommand::class);
        \WP_CLI::add_command('folderfolio smart', SmartCommand::class);
        \WP_CLI::add_command('folderfolio settings', SettingsCommand::class);
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

    /**
     * The same for a post, page or any other item filed since tier 3 item 12.
     *
     * `deleted_post` fires for attachments too, after `delete_attachment` has
     * already done this; revisions and menu items are never filed, and a site
     * saving a post deletes revisions often enough to skip them.
     *
     * @param int   $postId
     * @param mixed $post
     */
    public function forgetPost($postId, $post = null): void
    {
        $type = $post instanceof \WP_Post ? $post->post_type : '';

        if (in_array($type, ['attachment', 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache'], true)) {
            return;
        }

        // No schema yet, nothing filed: the tables are made on activation and
        // on admin_init after an update, and a post deleted before either
        // would otherwise print a database error.
        if (get_option(self::DB_VERSION_OPTION) === false) {
            return;
        }

        (new AttachmentFolderRepository())->deleteForAttachment((int) $postId);
    }

    public function registerRestRoutes(): void
    {
        (new FolderController())->registerRoutes();
        (new ImportController())->registerRoutes();
        (new PreferenceController())->registerRoutes();
        (new SmartController())->registerRoutes();
        (new SettingsController())->registerRoutes();
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

        self::install();

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
