<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mounts FolderFolio inside the native Media Library screen (upload.php).
 *
 * FolderFolio has no screen of its own: the folder tree is rendered into the
 * real library, in both grid and list mode.
 */
final class MediaLibraryIntegration
{
    private const SCREEN_HOOK = 'upload.php';

    /**
     * Compiled bundles this screen needs, mapped to their script dependencies.
     *
     * @var array<string, list<string>>
     */
    private const BUNDLES = [
        // 'folder-tree' is gone: the rail is a React app now (assets/src/apps)
        // and it renders the tree. 'bulk-actions' is gone too — it was the
        // v0.2.0 bulk bar, a pair of select-and-button controls bolted under
        // the bulk-actions row that reloaded the page on success and reported
        // failure through window.alert(). The Add-to-folder flyout replaces
        // it, inside WordPress's own filter row, with no reload and no dialog.
        // What is left here is the two bundles that work on the library rather
        // than on the rail, and they still listen for
        // folderfolio:folder-selected exactly as before.
        'media-library-integration' => ['wp-api-fetch'],
        'upload-integration' => ['wp-api-fetch', 'media-views'],
    ];

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);

        // The mount point moved to Rail. It used to be rendered here, into
        // all_admin_notices, which put the folder tree inside the content
        // column and in the notices stack — so it scrolled away with the page
        // and a plugin update notice could push it down the screen. Rail
        // renders #folderfolio-folder-tree inside the rail proper; this class
        // still owns the bundles that populate it.
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

        /*
         * The config these bundles read.
         *
         * This used to hang off 'folderfolio-folder-tree', and that handle
         * stopped being enqueued when the rail became a React app — so the
         * guard was never true and window.folderFolio was never set from here
         * at all. Nothing broke loudly, because core/api.ts's t() falls back
         * to the English literal at each call site; the symptom was every
         * label in these two bundles silently ignoring its translation.
         *
         * Hung off the first bundle that is actually enqueued now, and the
         * `||` matches Rail's: whichever of the two runs first wins, and the
         * other does not clobber it.
         */
        if (wp_script_is('folderfolio-media-library-integration', 'enqueued')) {
            wp_add_inline_script(
                'folderfolio-media-library-integration',
                'window.folderFolio = window.folderFolio || '
                    . wp_json_encode($this->config()) . ';',
                'before'
            );
        }
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
            'mediaNewUrl' => esc_url_raw(admin_url('media-new.php')),
            'version' => FOLDERFOLIO_VERSION,
            'canManageFolders' => current_user_can('upload_files'),
            // Every key here is read by upload-integration.ts or
            // media-library-integration.ts; the ones the deleted bulk bar
            // owned went with it. %s placeholders are filled positionally
            // on the client.
            'i18n' => [
                'upload' => __('Upload', 'folderfolio'),
                'assignFailed' => __('Could not assign the selected files.', 'folderfolio'),
                /* translators: %s is the number of media items assigned. */
                'assignSuccess' => __('Assigned %s file(s) to the folder.', 'folderfolio'),
                'uploadModalTitle' => __('Upload to Folder', 'folderfolio'),
            ],
        ];
    }
}
