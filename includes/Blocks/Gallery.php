<?php

declare(strict_types=1);

namespace FolderFolio\Blocks;

use FolderFolio\Support\Capabilities;
use FolderFolio\Support\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The gallery block — registration, and the two bundles it needs.
 *
 * Registered on `init` for everybody, not behind `is_admin()`: a block that
 * only registers in the admin renders as a "block contains unexpected content"
 * error on the front end, which is the classic way this goes wrong.
 *
 * The metadata lives in `blocks/gallery/block.json` so that core, the editor
 * and `wp_get_block_metadata()` all read the same file, and the markup lives in
 * `render.php` beside it. This class is the wiring: the handles block.json
 * names, and the config the editor's tree reads.
 */
final class Gallery
{
    public const NAME = 'folderfolio/gallery';

    private const EDITOR_HANDLE = 'folderfolio-gallery-editor';

    private const STYLE_HANDLE = 'folderfolio-gallery';

    public function register(): void
    {
        add_action('init', [$this, 'registerBlock']);
    }

    public function registerBlock(): void
    {
        $this->registerAssets();

        register_block_type(FOLDERFOLIO_PLUGIN_DIR . 'blocks/gallery');
    }

    /**
     * The handles `block.json` refers to by name.
     *
     * Registered, not enqueued: core enqueues `style` when the block is on the
     * page and the editor pair when the editor loads, which is what keeps a
     * page with no gallery on it free of the plugin's CSS.
     */
    private function registerAssets(): void
    {
        wp_register_style(
            self::STYLE_HANDLE,
            FOLDERFOLIO_PLUGIN_URL . 'assets/build/core/gallery.css',
            [],
            FOLDERFOLIO_VERSION
        );

        wp_register_style(
            self::EDITOR_HANDLE,
            FOLDERFOLIO_PLUGIN_URL . 'assets/build/core/block-editor.css',
            [],
            FOLDERFOLIO_VERSION
        );

        $manifest = FOLDERFOLIO_PLUGIN_DIR . 'assets/build/apps/gallery.asset.php';

        if (!file_exists($manifest)) {
            // No build, no editor script. The block still registers and still
            // renders: the front end has never needed the bundle, and a post
            // that already contains one keeps working.
            return;
        }

        /** @var array{dependencies: list<string>, version: string} $asset */
        $asset = require $manifest;

        wp_register_script(
            self::EDITOR_HANDLE,
            FOLDERFOLIO_PLUGIN_URL . 'assets/build/apps/gallery.js',
            array_merge($asset['dependencies'], [
                'wp-blocks',
                'wp-block-editor',
                'wp-components',
                'wp-api-fetch',
                'wp-server-side-render',
            ]),
            $asset['version'],
            ['in_footer' => true]
        );

        wp_add_inline_script(
            self::EDITOR_HANDLE,
            'window.folderFolio = window.folderFolio || ' . wp_json_encode($this->config()) . ';',
            'before'
        );
    }

    /**
     * What the inspector's tree reads.
     *
     * The same shape the rail and the media modal write, because the inspector
     * renders the same row and tree components — see
     * MediaModalIntegration::config(). Trimmed to what this screen can reach:
     * there is no create, rename or delete in a block inspector, so the labels
     * for them are not sent.
     *
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $settings = Settings::get();

        return [
            'restUrl' => esc_url_raw(rest_url('folderfolio/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'pluginUrl' => FOLDERFOLIO_PLUGIN_URL,
            'version' => FOLDERFOLIO_VERSION,

            // The tree component asks; in here every answer is false. A block
            // inspector is for choosing a folder, not for reorganising the
            // library — and the roles matrix still has the final say, so a
            // user who cannot create folders is not shown a control that
            // would fail.
            'can' => [
                'create' => false,
                'rename' => false,
                'delete' => false,
                'assign' => Capabilities::can('assign'),
            ],
            'countMode' => $settings['count_mode'],
            'defaultSort' => $settings['default_sort'],
            'i18n' => [
                'folders' => __('Folders', 'folderfolio'),
                'allMedia' => __('All media', 'folderfolio'),
                'unassigned' => __('Unassigned', 'folderfolio'),
                'searchPlaceholder' => __('Search folders', 'folderfolio'),
                'emptyTree' => __('No folders yet', 'folderfolio'),
                'retry' => __('Retry', 'folderfolio'),
                'treeFailed' => __('Could not load your folders.', 'folderfolio'),
                /* translators: %s is the search term that matched nothing. */
                'noMatch' => __('No folder matches “%s”.', 'folderfolio'),

                // Screen 09.
                'galleryTitle' => __('Folder gallery', 'folderfolio'),
                'galleryPrompt' => __(
                    'Pick a folder and every image in it becomes this gallery.',
                    'folderfolio'
                ),
                'chooseFolder' => __('Choose folder', 'folderfolio'),
                'changeFolder' => __('Change folder', 'folderfolio'),
                'folder' => __('Folder', 'folderfolio'),
                'includeSubfolders' => __('Include subfolders', 'folderfolio'),
                'layout' => __('Layout', 'folderfolio'),
                'layoutGrid' => __('Grid', 'folderfolio'),
                'layoutMasonry' => __('Masonry', 'folderfolio'),
                'columns' => __('Columns', 'folderfolio'),
                'gap' => __('Gap', 'folderfolio'),
                'orderBy' => __('Order by', 'folderfolio'),
                'orderNewest' => __('Newest first', 'folderfolio'),
                'orderOldest' => __('Oldest first', 'folderfolio'),
                'orderTitle' => __('Title, A to Z', 'folderfolio'),
                'orderMenu' => __('Media library order', 'folderfolio'),
                'orderRandom' => __('Random', 'folderfolio'),
                'limit' => __('Maximum images', 'folderfolio'),
                'limitAll' => __('All of them', 'folderfolio'),
                'linkTo' => __('Link to', 'folderfolio'),
                'linkNone' => __('Nothing', 'folderfolio'),
                'linkMedia' => __('The image file', 'folderfolio'),
                'linkAttachment' => __('The attachment page', 'folderfolio'),
                'gallerySettings' => __('Gallery', 'folderfolio'),
                /* translators: %s is a folder name. */
                'showingFolder' => __('Showing “%s”', 'folderfolio'),
            ],
        ];
    }
}
