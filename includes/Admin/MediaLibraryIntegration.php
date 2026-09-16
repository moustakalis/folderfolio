<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

/**
 * Mounts FolderFolio inside the native Media Library screen (upload.php).
 *
 * FolderFolio has no screen of its own: the folder tree is rendered into the
 * real library, in both grid and list mode.
 */
final class MediaLibraryIntegration
{
    private const SCREEN_HOOK = 'upload.php';

    private const SCREEN_ID = 'upload';

    /**
     * Compiled bundles this screen needs, mapped to their script dependencies.
     *
     * @var array<string, list<string>>
     */
    private const BUNDLES = [
        'folder-tree' => ['wp-api-fetch'],
        'media-library-integration' => ['wp-api-fetch'],
        'bulk-actions' => ['wp-api-fetch'],
        'upload-integration' => ['wp-api-fetch', 'media-views'],
    ];

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('all_admin_notices', [$this, 'renderMountPoint']);
    }

    /**
     * Enqueue built assets only on Media > Library.
     *
     * @param string $hookSuffix Current WordPress admin screen identifier.
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        if (self::SCREEN_HOOK !== $hookSuffix || !current_user_can('upload_files')) {
            return;
        }

        wp_enqueue_media();

        $stylePath = FOLDERFOLIO_PLUGIN_DIR . 'assets/build/core/admin.css';

        if (file_exists($stylePath)) {
            wp_enqueue_style(
                'folderfolio-admin',
                FOLDERFOLIO_PLUGIN_URL . 'assets/build/core/admin.css',
                [],
                FOLDERFOLIO_VERSION
            );
        }

        foreach (self::BUNDLES as $bundle => $dependencies) {
            $scriptPath = FOLDERFOLIO_PLUGIN_DIR . "assets/build/core/{$bundle}.js";

            if (!file_exists($scriptPath)) {
                continue;
            }

            wp_enqueue_script(
                "folderfolio-{$bundle}",
                FOLDERFOLIO_PLUGIN_URL . "assets/build/core/{$bundle}.js",
                $dependencies,
                FOLDERFOLIO_VERSION,
                ['in_footer' => true, 'strategy' => 'defer']
            );
        }

        if (wp_script_is('folderfolio-folder-tree', 'enqueued')) {
            wp_add_inline_script(
                'folderfolio-folder-tree',
                'window.folderFolio = ' . wp_json_encode($this->config()) . ';',
                'before'
            );
        }
    }

    /**
     * Render the container the folder tree mounts into.
     *
     * all_admin_notices fires inside #wpbody-content ahead of the page body,
     * which is the one insertion point shared by the grid and list views.
     */
    public function renderMountPoint(): void
    {
        if (!function_exists('get_current_screen')) {
            return;
        }

        $screen = get_current_screen();

        if (!$screen || self::SCREEN_ID !== $screen->id || !current_user_can('upload_files')) {
            return;
        }

        printf(
            '<div id="folderfolio-sidebar" class="folderfolio-sidebar"><div id="folderfolio-folder-tree" class="folderfolio-folder-tree" role="navigation" aria-label="%s"></div></div>',
            esc_attr__('Media folders', 'folderfolio')
        );
    }

    /**
     * Configuration handed to the frontend bundles.
     *
     * wp-api-fetch already applies the REST nonce; it is repeated here for
     * callers that build their own requests.
     *
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'restUrl' => esc_url_raw(rest_url('folderfolio/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'pluginUrl' => FOLDERFOLIO_PLUGIN_URL,
            'version' => FOLDERFOLIO_VERSION,
            'canManageFolders' => current_user_can('upload_files'),
            'i18n' => [
                'folders' => __('Folders', 'folderfolio'),
                'newFolder' => __('New', 'folderfolio'),
                'upload' => __('Upload', 'folderfolio'),
                'searchPlaceholder' => __('Search folders...', 'folderfolio'),
                'emptyTree' => __('No folders yet', 'folderfolio'),
                'namePrompt' => __('Enter folder name:', 'folderfolio'),
                'selectFolderFirst' => __('Please select a folder first', 'folderfolio'),
                'createFailed' => __('Failed to create folder', 'folderfolio'),
                'clearFilter' => __('Clear filter', 'folderfolio'),
            ],
        ];
    }
}
