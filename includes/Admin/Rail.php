<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Support\Capabilities;
use FolderFolio\Support\Settings;

/**
 * The folder rail's shell: where it sits, how wide it is, and whether it is
 * open. What goes inside it is not this class's business.
 *
 * ## Where it sits
 *
 * The design puts the rail beside the library, as a sibling of
 * `#wpbody-content`:
 *
 *     #wpbody
 *       ├ #folderfolio-rail          sticky, resizable, collapsible
 *       ├ #folderfolio-rail-handle   5px drag handle
 *       └ #wpbody-content            the library itself
 *
 * WordPress offers no hook between `<div id="wpbody">` and
 * `<div id="wpbody-content">`, so the markup cannot simply be printed there.
 * v0.2.0 dealt with that by rendering into `all_admin_notices` — inside the
 * content column — and then pushing the result around with CSS. That is why
 * the old rail scrolls away with the page, cannot be full height, and sits in
 * the notices stack where a plugin update notice can shove it down the screen.
 *
 * What this does instead: print the markup at the top of `#wpbody-content`
 * and immediately move it one level up with an inline script. The script runs
 * during parsing, while the rest of the content column has not been parsed
 * yet, so nothing has been laid out and nothing moves. The node is `hidden`
 * until it lands, so the one frame where it is in the wrong place is a frame
 * where it is not drawn.
 *
 * Inline, and not a file, on purpose: an enqueued script — even in the header
 * — runs after the whole document is parsed, which is a full render of the
 * library at the wrong width followed by a reflow. This is the case inline
 * script exists for.
 *
 * ## How wide it is
 *
 * From user meta, so the width is in the markup on first paint. See
 * RailPreferences.
 *
 * ## What goes inside
 *
 * For now, the existing tree node. The rail is being built shell-first so that
 * the mount point, stickiness, resize and collapse can be checked against
 * screens 03 and 04 on their own, before the contents are rebuilt. Moving the
 * current tree into it means the library keeps working meanwhile rather than
 * losing its folders for three commits.
 */
final class Rail
{
    private const SCREEN_HOOK = 'upload.php';

    private const SCREEN_ID = 'upload';

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_filter('admin_body_class', [$this, 'bodyClass']);

        // Priority 1: ahead of every notice, so the markup is the first thing
        // inside the content column and the move happens before anything else
        // is parsed.
        add_action('all_admin_notices', [$this, 'render'], 1);
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if (self::SCREEN_HOOK !== $hookSuffix || !$this->userMaySee()) {
            return;
        }

        // The shell's own script: resize, collapse, persistence. Vanilla, and
        // loaded whether or not the app below it does.
        if (file_exists(FOLDERFOLIO_PLUGIN_DIR . 'assets/build/core/rail.js')) {
            wp_enqueue_script(
                'folderfolio-rail',
                FOLDERFOLIO_PLUGIN_URL . 'assets/build/core/rail.js',
                ['wp-api-fetch'],
                FOLDERFOLIO_VERSION,
                ['in_footer' => true, 'strategy' => 'defer']
            );

            wp_add_inline_script(
                'folderfolio-rail',
                'window.folderFolioRail = ' . wp_json_encode($this->config()) . ';',
                'before'
            );
        }

        /*
         * The app. Its dependencies and version come from the .asset.php that
         * tools/esbuild.mjs writes beside the bundle, derived from what the
         * bundle actually imported — so `wp-element` can never fall out of
         * step with the code, which is the failure that makes a React app in
         * wp-admin work on one site and not another.
         */
        $manifest = FOLDERFOLIO_PLUGIN_DIR . 'assets/build/apps/rail.asset.php';

        if (!file_exists($manifest)) {
            return;
        }

        /** @var array{dependencies: list<string>, version: string} $asset */
        $asset = require $manifest;

        wp_enqueue_script(
            'folderfolio-rail-app',
            FOLDERFOLIO_PLUGIN_URL . 'assets/build/apps/rail.js',
            array_merge($asset['dependencies'], ['wp-api-fetch', 'wp-a11y']),
            $asset['version'],
            ['in_footer' => true, 'strategy' => 'defer']
        );

        wp_add_inline_script(
            'folderfolio-rail-app',
            'window.folderFolio = window.folderFolio || ' . wp_json_encode($this->appConfig()) . ';',
            'before'
        );
    }

    /**
     * Layout classes on <body>, so the flex row exists from first paint rather
     * than being switched on by script.
     *
     * @param string $classes
     */
    public function bodyClass($classes): string
    {
        $classes = (string) $classes;

        if (!$this->isTargetScreen()) {
            return $classes;
        }

        $classes .= ' folderfolio-has-rail';

        if (!RailPreferences::forUser(get_current_user_id())['open']) {
            $classes .= ' folderfolio-rail-collapsed';
        }

        return $classes;
    }

    public function render(): void
    {
        if (!$this->isTargetScreen()) {
            return;
        }

        $prefs = RailPreferences::forUser(get_current_user_id());
        $width = $prefs['open'] ? $prefs['width'] : RailPreferences::TAB_WIDTH;

        ?>
        <div
            id="folderfolio-rail"
            class="folderfolio folderfolio-rail<?php echo $prefs['open'] ? '' : ' is-collapsed'; ?>"
            style="--ff-rail-w: <?php echo (int) $width; ?>px"
            hidden
        >
            <?php
            /*
             * The React root. Empty on the server because everything in it
             * depends on data this page does not have — and printing a
             * skeleton here would mean maintaining the rail's markup twice,
             * once in PHP and once in TSX, with only a visual diff to catch
             * them drifting. The app renders ghost rows while the tree loads.
             *
             * The rail's own geometry is server-rendered, which is the part
             * that has to be right before first paint; what goes inside it can
             * arrive a frame later without anything moving.
             */
            ?>
            <div
                id="folderfolio-rail-app"
                class="folderfolio-rail__app"
                role="navigation"
                aria-label="<?php esc_attr_e('Media folders', 'folderfolio'); ?>"
            ></div>

            <div class="folderfolio-rail__footer">
                <span class="folderfolio-rail__total" data-folderfolio-total></span>
                <button
                    type="button"
                    class="folderfolio-rail__collapse"
                    data-folderfolio-collapse
                    aria-controls="folderfolio-rail"
                    aria-expanded="true"
                >
                    <?php echo self::icon('m15 18-6-6 6-6', 12); ?>
                    <?php esc_html_e('Collapse', 'folderfolio'); ?>
                </button>
            </div>

            <?php
            /*
             * The collapsed tab. Rendered always and hidden by CSS rather than
             * swapped in by script: at 28px it costs nothing, and it means
             * collapsing and expanding is a class change with no DOM work and
             * so no chance of a frame where the rail is neither.
             */
            ?>
            <div class="folderfolio-rail__tab">
                <button
                    type="button"
                    class="folderfolio-rail__expand"
                    data-folderfolio-expand
                    aria-controls="folderfolio-rail"
                    aria-expanded="false"
                    title="<?php esc_attr_e('Show folders', 'folderfolio'); ?>"
                >
                    <span class="screen-reader-text"><?php esc_html_e('Show folders', 'folderfolio'); ?></span>
                    <?php echo self::icon('m9 18 6-6-6-6', 12); ?>
                </button>
                <span class="folderfolio-rail__tab-label" aria-hidden="true">
                    <?php esc_html_e('Folders', 'folderfolio'); ?>
                </span>
            </div>
        </div>

        <?php
        /*
         * A separator rather than a button: this is a window splitter, and
         * role="separator" with a tabindex is the pattern screen readers
         * announce as one. It carries its own value so the width is reachable
         * and adjustable from the keyboard — arrow keys, handled in rail.ts.
         */
        ?>
        <div
            id="folderfolio-rail-handle"
            class="folderfolio folderfolio-resize"
            role="separator"
            tabindex="0"
            aria-orientation="vertical"
            aria-controls="folderfolio-rail"
            aria-label="<?php esc_attr_e('Resize the folders panel', 'folderfolio'); ?>"
            aria-valuenow="<?php echo (int) $prefs['width']; ?>"
            aria-valuemin="<?php echo RailPreferences::MIN_WIDTH; ?>"
            aria-valuemax="<?php echo RailPreferences::MAX_WIDTH; ?>"
            hidden
        ></div>

        <script>
            (function () {
                var body = document.getElementById('wpbody'),
                    content = document.getElementById('wpbody-content'),
                    rail = document.getElementById('folderfolio-rail'),
                    handle = document.getElementById('folderfolio-rail-handle');

                if (!body || !content || !rail || !handle) {
                    return;
                }

                body.insertBefore(rail, content);
                body.insertBefore(handle, content);

                rail.hidden = false;
                handle.hidden = rail.classList.contains('is-collapsed');
            })();
        </script>
        <?php
    }

    /**
     * Inline SVG rather than a sprite or a font.
     *
     * currentColor is the whole point: these sit inside buttons whose colour
     * comes from the admin scheme, and anything with a baked-in fill would be
     * the one wrong-coloured thing on seven of the eight schemes.
     */
    private static function icon(string $path, int $size): string
    {
        return sprintf(
            '<svg width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
                . ' stroke-width="2.5" stroke-linecap="square" aria-hidden="true" focusable="false">'
                . '<path d="%2$s"></path></svg>',
            $size,
            esc_attr($path)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $prefs = RailPreferences::forUser(get_current_user_id());

        return [
            'open' => $prefs['open'],
            'width' => $prefs['width'],
            'defaultWidth' => RailPreferences::DEFAULT_WIDTH,
            'minWidth' => RailPreferences::MIN_WIDTH,
            'maxWidth' => RailPreferences::MAX_WIDTH,
            'i18n' => [
                // The only string the script builds rather than the markup
                // carrying it: the width announcement for the splitter.
                /* translators: %d is the rail width in pixels. */
                'width' => __('Folders panel width: %d pixels', 'folderfolio'),
            ],
        ];
    }

    /**
     * Labels for the app.
     *
     * Merged into window.folderFolio rather than a second global, because
     * core/api.ts's t() already reads that object and every existing bundle
     * shares it. MediaLibraryIntegration sets it first when it is enqueued;
     * the `||` above means whichever runs first wins and the other does not
     * clobber it.
     *
     * @return array<string, mixed>
     */
    private function appConfig(): array
    {
        $settings = Settings::get();

        return [
            'restUrl' => esc_url_raw(rest_url('folderfolio/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'pluginUrl' => FOLDERFOLIO_PLUGIN_URL,
            'version' => FOLDERFOLIO_VERSION,
            /*
             * The four abilities of the roles matrix, resolved for this user.
             *
             * This used to be a single `canManageFolders` set to
             * current_user_can('upload_files') — which nothing read, and which
             * would have been the wrong answer if it had.
             */
            'can' => [
                'create' => Capabilities::can('create'),
                'rename' => Capabilities::can('rename'),
                'delete' => Capabilities::can('delete'),
                'assign' => Capabilities::can('assign'),
            ],

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
            'i18n' => [
                'folders' => __('Folders', 'folderfolio'),
                'newFolder' => __('New folder', 'folderfolio'),
                'save' => __('Save', 'folderfolio'),
                'cancel' => __('Cancel', 'folderfolio'),
                'retry' => __('Retry', 'folderfolio'),
                'allMedia' => __('All media', 'folderfolio'),
                'unassigned' => __('Unassigned', 'folderfolio'),
                'atTopLevel' => __('Top level', 'folderfolio'),
                'createAtRoot' => __('New folder at the top level', 'folderfolio'),
                'createInFolder' => __('New folder inside the selected folder', 'folderfolio'),
                'searchPlaceholder' => __('Search folders', 'folderfolio'),
                'folderActions' => __('Folder actions', 'folderfolio'),
                'rename' => __('Rename', 'folderfolio'),
                'renameFolder' => __('Rename folder', 'folderfolio'),
                'newFolderName' => __('Name for the new folder', 'folderfolio'),
                'delete' => __('Delete', 'folderfolio'),
                'sort' => __('Sort', 'folderfolio'),
                'sortNameAsc' => __('Name, A to Z', 'folderfolio'),
                'sortNameDesc' => __('Name, Z to A', 'folderfolio'),
                'sortNewest' => __('Newest first', 'folderfolio'),
                'sortOldest' => __('Oldest first', 'folderfolio'),
                'undo' => __('Undo', 'folderfolio'),
                /* translators: %s is the folder name. */
                'deleted' => __('Deleted “%s”', 'folderfolio'),
                /* translators: 1: folder name, 2: number of files. */
                'deletedWithFiles' => __(
                    'Deleted “%s” — %s files moved to Unassigned',
                    'folderfolio'
                ),
                'emptyTree' => __('No folders yet', 'folderfolio'),
                /* translators: %s is the number of folders. */
                'folderTotal' => __('%s folders', 'folderfolio'),
                'folderTotalOne' => __('1 folder', 'folderfolio'),

                // The colour picker behind More — screen 11, §9.8. The ten
                // names are labels for a swatch, not colour codes: they are
                // what a screen reader announces and what the tooltip shows,
                // so they are translated like any other visible word.
                'more' => __('More', 'folderfolio'),
                'folderColor' => __('Folder colour', 'folderfolio'),
                /* translators: %s is the folder name. */
                'colorFor' => __('Colour for %s', 'folderfolio'),
                'noColor' => __('No colour', 'folderfolio'),
                'swatchSlate' => __('Slate', 'folderfolio'),
                'swatchRed' => __('Red', 'folderfolio'),
                'swatchClay' => __('Clay', 'folderfolio'),
                'swatchOchre' => __('Ochre', 'folderfolio'),
                'swatchMoss' => __('Moss', 'folderfolio'),
                'swatchTeal' => __('Teal', 'folderfolio'),
                'swatchSteel' => __('Steel', 'folderfolio'),
                'swatchIndigo' => __('Indigo', 'folderfolio'),
                'swatchPlum' => __('Plum', 'folderfolio'),
                'swatchInk' => __('Ink', 'folderfolio'),

                // The filter-row controls — screens 03, 06 and 11.
                'filterByFolder' => __('Filter by folder', 'folderfolio'),
                'addToFolder' => __('Add to folder', 'folderfolio'),
                'moveToFolder' => __('Move to folder', 'folderfolio'),
                'moveNeedsFolder' => __(
                    'Open a folder first — a move needs a folder to move out of.',
                    'folderfolio'
                ),
                /* translators: %s is the folder the files are being moved out of. */
                'movesOutOf' => __('Moves them out of “%s”', 'folderfolio'),
                'moveFailed' => __('Could not move those files.', 'folderfolio'),
                /* translators: 1: number of files, 2: the destination folder name. */
                'movedFile' => __('Moved %s file to %s', 'folderfolio'),
                /* translators: 1: number of files, 2: the destination folder name. */
                'movedFiles' => __('Moved %s files to %s', 'folderfolio'),
                'findFolder' => __('Find a folder', 'folderfolio'),
                'addsACopy' => __('Adds a copy of the membership', 'folderfolio'),
                /* translators: %s is the number of folders not shown. */
                'andMoreFolders' => __('%s more — keep typing to narrow', 'folderfolio'),
                'addFailed' => __('Could not file those files.', 'folderfolio'),
                /* translators: %s is the number of selected media files. */
                'fileSelected' => __('%s file selected', 'folderfolio'),
                /* translators: %s is the number of selected media files. */
                'filesSelected' => __('%s files selected', 'folderfolio'),
                /* translators: 1: number of files, 2: a comma-separated list of folder names. */
                'addedFile' => __('Added %s file to %s', 'folderfolio'),
                /* translators: 1: number of files, 2: a comma-separated list of folder names. */
                'addedFiles' => __('Added %s files to %s', 'folderfolio'),
                'createFailed' => __('Could not create that folder.', 'folderfolio'),
                'treeFailed' => __('Could not load your folders.', 'folderfolio'),
                'treeFailedWhere' => __(
                    'The request to /folderfolio/v1/folders did not succeed.',
                    'folderfolio'
                ),
                /* translators: %s is the search term that matched nothing. */
                'noMatch' => __('No folder matches “%s”.', 'folderfolio'),
            ],
        ];
    }

    private function isTargetScreen(): bool
    {
        if (!function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();

        return $screen && self::SCREEN_ID === $screen->id && $this->userMaySee();
    }

    private function userMaySee(): bool
    {
        return current_user_can('upload_files');
    }
}
