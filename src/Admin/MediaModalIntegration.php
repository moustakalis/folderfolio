<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

/**
 * Media Modal integration - adds folder tree to wp.media picker.
 */
class MediaModalIntegration
{
    public function register(): void
    {
        // Enqueue assets for all admin pages with media modals
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets(string $hook): void
    {
        // Load on all admin pages where media modals might appear
        $modalHooks = [
            'post.php',
            'post-new.php',
            'edit.php',
            'upload.php',
            'media-new.php',
            'themes.php',
            'customize.php',
        ];

        if (!in_array($hook, $modalHooks, true)) {
            return;
        }

        $scriptPath = FOLDERFOLIO_PLUGIN_DIR . 'assets/build/core/media-modal.js';
        $stylePath = FOLDERFOLIO_PLUGIN_DIR . 'assets/build/core/media-modal.css';

        if (file_exists($scriptPath)) {
            wp_enqueue_script(
                'folderfolio-media-modal',
                FOLDERFOLIO_PLUGIN_URL . 'assets/build/core/media-modal.js',
                ['wp-api-fetch', 'wp-media-utils', 'jquery'],
                FOLDERFOLIO_VERSION,
                ['in_footer' => true]
            );
        }

        if (file_exists($stylePath)) {
            wp_enqueue_style(
                'folderfolio-media-modal',
                FOLDERFOLIO_PLUGIN_URL . 'assets/build/core/media-modal.css',
                ['folderfolio-admin'],
                FOLDERFOLIO_VERSION
            );
        }
    }
}
