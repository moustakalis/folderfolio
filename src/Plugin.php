<?php

declare(strict_types=1);

namespace FolderFolio;

use FolderFolio\Database\Schema;

class Plugin
{
    public function boot(): void
    {
        // Load text domain.
        add_action('init', function () {
            load_plugin_textdomain('folderfolio', FOLDERFOLIO_PLUGIN_DIR . '/languages');
        });

        // Initialize core services.
        add_action('init', function () {
            // Future: register REST routes, admin integrations, etc.
        }, 1);

        // Enqueue admin assets.
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
    }

    public function activate(): void
    {
        // Run database migrations.
        $schema = new Schema();
        $schema->migrate();

        // Flush rewrite rules (if needed later).
        flush_rewrite_rules(false);
    }

    public function deactivate(): void
    {
        // No cleanup needed: folder metadata remains intact.
        flush_rewrite_rules(false);
    }

    public function enqueueAdminAssets(string $hook): void
    {
        // Only load on Media Library and related admin pages.
        if ($hook !== 'upload.php' && $hook !== 'media-new.php') {
            return;
        }

        wp_enqueue_script(
            'folderfolio-admin',
            FOLDERFOLIO_PLUGIN_URL . 'assets/build/core/folder-tree.js',
            ['wp-api-fetch', 'wp-dom-ready'],
            FOLDERFOLIO_VERSION,
            true
        );

        wp_enqueue_style(
            'folderfolio-admin',
            FOLDERFOLIO_PLUGIN_URL . 'assets/build/core/admin.css',
            [],
            FOLDERFOLIO_VERSION
        );
    }
}
