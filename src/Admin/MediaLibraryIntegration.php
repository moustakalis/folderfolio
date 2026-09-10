<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

/**
 * Integrates FolderFolio with the WordPress Media Library.
 */
final class MediaLibraryIntegration
{
    /**
     * Register Media Library hooks.
     */
    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);

        add_filter(
            'media_upload_filters',
            [$this, 'renderFolderFilter'],
            10,
            1
        );
    }

    /**
     * Enqueue built assets only on Media > Library.
     *
     * @param string $hookSuffix Current WordPress admin screen identifier.
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        if ('upload.php' !== $hookSuffix) {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_script(
            'folderfolio-media-library',
            FOLDERFOLIO_PLUGIN_URL . 'assets/build/core/media-library-integration.js',
            [],
            FOLDERFOLIO_VERSION,
            [
                'in_footer' => true,
                'strategy'  => 'defer',
            ]
        );

        wp_enqueue_style(
            'folderfolio-media-library',
            FOLDERFOLIO_PLUGIN_URL . 'assets/build/core/media-modal.css',
            [],
            FOLDERFOLIO_VERSION
        );
    }

    /**
     * Adds FolderFolio's label to supported Media Library filter collections.
     *
     * @param array<string, string> $filters Existing filter labels.
     * @return array<string, string> Updated filter labels.
     */
    public function renderFolderFilter(array $filters): array
    {
        $filters['folderfolio_all'] = __('All Folders', 'folderfolio');

        return $filters;
    }
}
