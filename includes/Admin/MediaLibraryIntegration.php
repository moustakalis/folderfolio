<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Modules\Import\Elsewhere;
use FolderFolio\Support\Assets;
use FolderFolio\Support\Capabilities;
use FolderFolio\Support\ClientConfig;
use FolderFolio\Support\PostTypes;
use FolderFolio\Support\Settings;

/**
 * Mounts FolderFolio inside the native Media Library screen (upload.php).
 *
 * FolderFolio has no screen of its own: the folder tree is rendered into the
 * real library, in both grid and list mode.
 */
final class MediaLibraryIntegration
{
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
        //
        // 'upload-integration' is gone as well, and unlike those two it was
        // not replaced by anything: every path through it was unreachable.
        // See the commit that removed it. "Uploads go to the selected folder"
        // is an unimplemented feature, not a regression.
        //
        // What is left is the one bundle that works on the library rather
        // than on the rail, and it still listens for
        // folderfolio:folder-selected exactly as before.
        'media-library-integration' => ['wp-api-fetch'],
    ];

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);

        // The mount point moved to Rail. It used to be rendered here, into
        // all_admin_notices, which put the folder tree inside the content
        // column and in the notices stack — so it scrolled away with the page
        // and a plugin update notice could push it down the screen. Rail owns
        // the rail's own markup now; this class still owns the bundles that
        // populate it.
        //
        // The id this comment used to name, #folderfolio-folder-tree, was
        // v0.2.0's and is rendered by nothing — its last trace was ~200 lines
        // of dead CSS in admin.css, removed 20 Sep.
    }

    /**
     * Enqueue built assets on Media > Library — and, since tier 3 item 12, on
     * a post type's list screen when its folders are on (`Rail::screenType()`).
     *
     * @param string $hookSuffix Current WordPress admin screen identifier.
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        $type = Rail::screenType();

        if (!in_array($hookSuffix, ['upload.php', 'edit.php'], true) || null === $type) {
            return;
        }

        // The grid's frame is media's alone; a post list has no wp.media to
        // keep in step.
        if (PostTypes::MEDIA === $type) {
            wp_enqueue_media();
        }

        $stylePath = FOLDERFOLIO_PLUGIN_DIR . 'assets/build/core/admin.css';

        if (file_exists($stylePath)) {
            wp_enqueue_style(
                'folderfolio-admin',
                FOLDERFOLIO_PLUGIN_URL . 'assets/build/core/admin.css',
                [],
                Assets::version('assets/build/core/admin.css')
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
                Assets::version("assets/build/core/{$bundle}.js"),
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
         * Hung off the first bundle that is actually enqueued now, through
         * the same merging writer as every other screen — Support\ClientConfig.
         */
        if (wp_script_is('folderfolio-media-library-integration', 'enqueued')) {
            wp_add_inline_script(
                'folderfolio-media-library-integration',
                ClientConfig::script($this->config($type)),
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
    private function config(string $type): array
    {
        $settings = Settings::get();

        // The type, its labels and the abilities for it — the same keys the
        // rail's own config carries, from the same method, because whichever
        // of the two is printed first wins window.folderFolio.
        return Rail::typeConfig($type) + [
            'restUrl' => esc_url_raw(rest_url('folderfolio/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'pluginUrl' => FOLDERFOLIO_PLUGIN_URL,
            'version' => FOLDERFOLIO_VERSION,

            /*
             * Site settings — screen 08.
             *
             * Both writers of window.folderFolio carry them, because whichever
             * bundle is enqueued first wins and the other does not clobber it;
             * a key present in only one of the two is a setting that applies
             * on some screens and not others.
             */
            'countMode' => $settings['count_mode'],
            'defaultSort' => $settings['default_sort'],
            'undoWindow' => $settings['undo_window'],

            // And the rail's empty state, for the same reason as the three
            // above: whichever of the two bundles is enqueued first wins the
            // `||`, so a key in only one of them is a feature that works on
            // some page loads.
            'elsewhere' => PostTypes::MEDIA === $type ? Elsewhere::forConfig() : null,
            // No 'i18n' here any more. All four labels belonged to
            // upload-integration.ts, and media-library-integration.ts — the
            // only bundle left on this screen — renders no text of its own.
            // The rail localises its own set from Rail.php, on the same
            // screen and under the same key.
        ];
    }
}
