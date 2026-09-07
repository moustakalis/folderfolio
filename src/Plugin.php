<?php

declare(strict_types=1);

namespace FolderFolio;

use FolderFolio\Database\Schema;
use FolderFolio\Rest\FolderController;

final class Plugin
{
    public function boot(): void
    {
        add_action('init', [$this, 'loadTextDomain']);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
    }

    public function activate(): void
    {
        (new Schema())->migrate();
    }

    public function deactivate(): void
    {
        // Folder metadata is deliberately retained on deactivation.
    }

    public function loadTextDomain(): void
    {
        load_plugin_textdomain('folderfolio', false, dirname(plugin_basename(FOLDERFOLIO_PLUGIN_FILE)) . '/languages');
    }

    public function registerRestRoutes(): void
    {
        (new FolderController())->registerRoutes();
    }

    public function enqueueAdminAssets(string $hook): void
    {
        if (!in_array($hook, ['upload.php', 'media-new.php'], true)) {
            return;
        }

        $scriptPath = FOLDERFOLIO_PLUGIN_DIR . 'assets/build/core/folder-tree.js';
        $stylePath = FOLDERFOLIO_PLUGIN_DIR . 'assets/build/core/admin.css';

        if (file_exists($scriptPath)) {
            wp_enqueue_script(
                'folderfolio-admin',
                FOLDERFOLIO_PLUGIN_URL . 'assets/build/core/folder-tree.js',
                ['wp-api-fetch'],
                FOLDERFOLIO_VERSION,
                ['in_footer' => true]
            );
        }

        if (file_exists($stylePath)) {
            wp_enqueue_style(
                'folderfolio-admin',
                FOLDERFOLIO_PLUGIN_URL . 'assets/build/core/admin.css',
                [],
                FOLDERFOLIO_VERSION
            );
        }
    }
}
