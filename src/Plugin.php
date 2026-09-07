<?php

declare(strict_types=1);

namespace FolderFolio;

use FolderFolio\Database\Schema;
use FolderFolio\Rest\FolderController;
use FolderFolio\Rest\ImportController;
use FolderFolio\Admin\MediaLibraryIntegration;
use FolderFolio\Admin\MediaModalIntegration;
use FolderFolio\Admin\ImportPage;

final class Plugin
{
    public function boot(): void
    {
        add_action('init', [$this, 'loadTextDomain']);
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);

        // Register admin integrations
        if (is_admin()) {
            (new MediaLibraryIntegration())->register();
            (new MediaModalIntegration())->register();
            (new ImportPage())->register();
        }
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
        (new ImportController())->registerRoutes();
    }

    public function enqueueAdminAssets(string $hook): void
    {
        if (!in_array($hook, ['upload.php', 'media-new.php'], true)) {
            return;
        }

        $scriptPath = FOLDERFOLIO_PLUGIN_DIR . 'assets/build/core/folder-tree.js';
        $stylePath = FOLDERFOLIO_PLUGIN_DIR . 'assets/build/core/admin.css';
        $bulkPath = FOLDERFOLIO_PLUGIN_DIR . 'assets/build/core/bulk-actions.js';
        $uploadPath = FOLDERFOLIO_PLUGIN_DIR . 'assets/build/core/upload-integration.js';

        if (file_exists($scriptPath)) {
            wp_enqueue_script(
                'folderfolio-admin',
                FOLDERFOLIO_PLUGIN_URL . 'assets/build/core/folder-tree.js',
                ['wp-api-fetch', 'jquery'],
                FOLDERFOLIO_VERSION,
                ['in_footer' => true]
            );
        }

        if (file_exists($bulkPath)) {
            wp_enqueue_script(
                'folderfolio-bulk',
                FOLDERFOLIO_PLUGIN_URL . 'assets/build/core/bulk-actions.js',
                ['wp-api-fetch', 'folderfolio-admin'],
                FOLDERFOLIO_VERSION,
                ['in_footer' => true]
            );
        }

        if (file_exists($uploadPath)) {
            wp_enqueue_script(
                'folderfolio-upload',
                FOLDERFOLIO_PLUGIN_URL . 'assets/build/core/upload-integration.js',
                ['wp-api-fetch', 'folderfolio-admin'],
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

        // Inject folder tree container into Media Library sidebar
        add_action('admin_print_scripts-upload.php', [$this, 'printFolderTreeContainer'], 1);
    }

    public function printFolderTreeContainer(): void
    {
        ?>
        <style>
            #folderfolio-sidebar {
                margin: 20px 0;
                padding: 0 10px;
            }
        </style>
        <script>
        (function($) {
            $(document).ready(function() {
                var $sidebar = $('#folderfolio-sidebar');
                if ($sidebar.length === 0) {
                    $sidebar = $('<div id="folderfolio-sidebar"></div>');
                    $('.wp-filter').first().before($sidebar);
                }
                $sidebar.html('<div id="folderfolio-folder-tree"></div>');
            });
        })(jQuery);
        </script>
        <?php
    }
}
