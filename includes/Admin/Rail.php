<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Domain\FolderPath;
use FolderFolio\Modules\Import\Elsewhere;
use FolderFolio\Support\Assets;
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
 * ## And it has to survive a neighbour rebuilding the page
 *
 * Printing inside `#wpbody-content` has a second consequence, measured under
 * Premio's Folders: a plugin that refetches this screen and swaps
 * `#wpbody-content` in gets *a copy of this markup* for free. The live rail is
 * taken out of the document — not destroyed; its React root survives being
 * detached — and an inert copy arrives in its place. So the script below
 * compares by identity, sweeps copies, and puts the live node back. See its
 * comments for the numbers.
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
                Assets::version('assets/build/core/rail.js'),
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
            // wp-plupload is what puts wp.Uploader on the page, and
            // core/upload-target.ts needs it to exist before it can follow the
            // folder selection onto an upload. Core loads it on this screen
            // anyway; declaring it is what makes the order a fact rather than
            // a coincidence.
            array_merge($asset['dependencies'], ['wp-api-fetch', 'wp-a11y', 'wp-plupload']),
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

        /*
         * The width the rail actually occupies, published where the rest of
         * the admin page can read it.
         *
         * Core's #wpfooter is absolutely positioned across the whole content
         * area, the rail's column included, so its text slides under the rail
         * and — because it comes later in the document — its transparent box
         * swallows clicks meant for Collapse. _rail.css indents the footer by
         * this much. It is a second property rather than --ff-rail-w because
         * that one keeps the stored width while the rail is collapsed, which
         * is what reopening restores; this one is the width on screen now.
         */
        ?>
        <style id="folderfolio-rail-gutter">
            body.folderfolio-has-rail { --ff-rail-gutter: <?php echo (int) $width; ?>px; }
        </style>
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
                /*
                 * The live nodes, captured once and compared by identity
                 * everywhere below — never looked up by id again.
                 *
                 * Because a plugin that refetches this page brings a *copy* of
                 * this markup back with it. Premio's Folders runs
                 * jQuery('#wpbody').load(url + ' #wpbody-content') on a folder
                 * click, and since this markup is printed on all_admin_notices
                 * — which is inside #wpbody-content — the fetched fragment
                 * carries a second #folderfolio-rail. getElementById would
                 * hand that one over, and the app is not mounted in it.
                 */
                var rail = document.getElementById('folderfolio-rail'),
                    handle = document.getElementById('folderfolio-rail-handle');

                if (!rail || !handle || !document.getElementById('wpbody')) {
                    return;
                }

                var narrow = window.matchMedia('(max-width: 782px)');

                /*
                 * How far the rail is pulled toward the admin menu — library
                 * finding 5, and the one rule we used to write against
                 * somebody else's element.
                 *
                 * Core gives #wpcontent a 20px inline-start padding, which put
                 * a band of page background between the dark menu and the
                 * white rail. We zeroed it. Premio's Folders writes
                 * `body.wp-admin #wpcontent { padding-left: 305px }` at
                 * exactly the same specificity to reserve room for its own
                 * fixed rail — ours loaded later, ours won, nothing reserved
                 * the room, and their rail was drawn on top of ours. Winning
                 * was the bug.
                 *
                 * So we read that padding instead of writing it. Whatever is
                 * there, if it is no larger than core's own, is what the rail
                 * pulls back by (_rail.css does the pulling, from a negative
                 * inline-start margin on our own element). If somebody has
                 * made it larger they are using it for something: the pull
                 * goes to zero, their reservation stands, both rails are
                 * legible, and the only thing lost is a 20px seam.
                 *
                 * `paddingInlineStart` rather than `paddingLeft` because
                 * wp-admin's RTL stylesheet flips core's own declaration.
                 */
                var CORE_PADDING = 20;

                function pull() {
                    var content = document.getElementById('wpcontent'),
                        padding;

                    if (!content) {
                        return;
                    }

                    padding = parseFloat(
                        window.getComputedStyle(content).paddingInlineStart
                    ) || 0;

                    /*
                     * Two halves of one measurement. What the rail takes back,
                     * and what it leaves — which #wpfooter still has to clear,
                     * because its box is positioned against an ancestor
                     * further out and does not move with this padding at all.
                     */
                    var taken = padding > CORE_PADDING ? 0 : padding;

                    document.body.style.setProperty('--ff-rail-pull', taken + 'px');
                    document.body.style.setProperty(
                        '--ff-rail-inset',
                        (padding - taken) + 'px'
                    );
                }

                /*
                 * Two homes, because the rail is two different things.
                 *
                 * Wide, it is a column beside the library, so it belongs in
                 * #wpbody as a sibling of #wpbody-content — which is the only
                 * way to get a node between <div id="wpbody"> and
                 * <div id="wpbody-content">, since WordPress offers no hook
                 * there.
                 *
                 * Narrow, it is a band *of* the library, and printing it as
                 * the first thing in #wpbody put it above WordPress's own
                 * screen-meta row: measured, the band pushed Help to 421px and
                 * the "Media Library" heading to 481px down the page. It read
                 * as sitting on top of the admin's own furniture, because it
                 * was. So it moves inside .wrap, immediately after the page
                 * heading, where it reads as the library's own control
                 * surface and leaves the admin header alone.
                 */
                function place() {
                    /*
                     * Both re-resolved on every call rather than captured,
                     * because #wpbody-content is exactly the node a rival
                     * replaces. A captured reference is a detached div, and
                     * inserting the rail before it puts the rail somewhere
                     * nobody is looking.
                     */
                    var body = document.getElementById('wpbody'),
                        content = document.getElementById('wpbody-content');

                    if (!body || !content) {
                        return;
                    }

                    if (narrow.matches) {
                        var wrap = content.querySelector('.wrap'),
                            anchor = wrap
                                && (wrap.querySelector('.wp-header-end') || wrap.querySelector('h1'));

                        if (anchor) {
                            var after = anchor.nextSibling;

                            /*
                             * `insertBefore(rail, rail)` is legal, does
                             * nothing visible, and still fires a removal and
                             * an insertion — which is enough to swallow any
                             * click whose mousedown and mouseup straddle it.
                             * This project has paid for that once already.
                             */
                            if (rail !== after) {
                                anchor.parentNode.insertBefore(rail, after);
                            }

                            handle.hidden = true;

                            return;
                        }
                    }

                    /*
                     * The test has to describe the finished arrangement, not
                     * half of it: rail, then handle, then the content column.
                     *
                     * `rail.nextSibling === content` is false the moment the
                     * handle is in place, so this re-ran both inserts on every
                     * call. Harmless while place() ran twice a page load. An
                     * infinite loop the moment a MutationObserver calls it,
                     * because each insert is a mutation and each mutation
                     * schedules another repair. Measured: a frozen renderer on
                     * upload.php.
                     */
                    if (rail.parentNode !== body
                        || rail.nextSibling !== handle
                        || handle.nextSibling !== content) {
                        body.insertBefore(rail, content);
                        body.insertBefore(handle, content);
                    }

                    handle.hidden = rail.classList.contains('is-collapsed');
                }

                /*
                 * Anything wearing one of our two ids that is not one of our
                 * two nodes is a copy that came back with somebody's refetched
                 * markup, and it is a shell: the React root mounted into the
                 * live node and stays mounted there even while that node is
                 * detached, so the copy has an empty #folderfolio-rail-app and
                 * no tree in it. Measured under Premio in a 700px viewport, it
                 * drew 668px of empty panel and pushed the file table to
                 * top: 953px. A dead shell is worse than an absence, because
                 * it looks present.
                 *
                 * The <style id="folderfolio-rail-gutter"> copy is left alone
                 * on purpose: it carries the same single declaration, and the
                 * live one is inside the content column, so a refetch destroys
                 * ours and the copy is what keeps the footer indented.
                 */
                function sweepCopies() {
                    var copies = document.querySelectorAll(
                            '#folderfolio-rail, #folderfolio-rail-handle'
                        ),
                        i,
                        node;

                    for (i = 0; i < copies.length; i++) {
                        node = copies[i];

                        if (node !== rail && node !== handle && node.parentNode) {
                            node.parentNode.removeChild(node);
                        }
                    }
                }

                /*
                 * Placement has to survive somebody else rebuilding the page
                 * around it. Note the order: throw the copy away first, so
                 * that place() is never choosing between two nodes with the
                 * same id.
                 */
                var pending = false;

                function repair() {
                    pending = false;

                    /*
                     * Deaf while it works. Our own removals and insertions are
                     * mutations like anybody else's, and disconnecting empties
                     * the record queue, so a repair can never schedule itself.
                     * The corrected test above already makes the common case a
                     * no-op; this is what makes it true by construction rather
                     * than by the test being right.
                     */
                    observer.disconnect();

                    try {
                        sweepCopies();
                        place();
                    } finally {
                        watch();
                    }
                }

                function schedule() {
                    if (pending) {
                        return;
                    }

                    pending = true;

                    // A microtask: one repair for a whole .html() call's worth
                    // of mutations, and it lands before the next paint.
                    Promise.resolve().then(repair);
                }

                var observer = new MutationObserver(function (records) {
                    for (var i = 0; i < records.length; i++) {
                        // The app's own renders are mutations too, and in a
                        // tree of a thousand folders there are a great many
                        // of them.
                        if (rail.contains(records[i].target)) {
                            continue;
                        }

                        schedule();

                        return;
                    }
                });

                // #wpbody survives a .load() of its own contents, so this
                // observer outlives the thing it is watching for.
                function watch() {
                    var body = document.getElementById('wpbody');

                    if (body) {
                        observer.observe(body, { childList: true, subtree: true });
                    }
                }

                /*
                 * The wide home is taken at once, because this script is
                 * printed at the top of #wpbody-content and .wrap has not been
                 * parsed yet — there is nothing to anchor to. The narrow home
                 * is taken as soon as there is, which is still before any
                 * bundle of ours runs.
                 */
                place();
                pull();

                rail.hidden = false;
                handle.hidden = rail.classList.contains('is-collapsed');

                watch();

                /*
                 * Once more when everything has loaded, in case a stylesheet
                 * that claims that padding arrives in the footer. One forced
                 * style resolution, after the page is done; not on every
                 * mutation, which is where a getComputedStyle belongs least.
                 */
                window.addEventListener('load', pull, { once: true });

                if ('loading' === document.readyState) {
                    document.addEventListener('DOMContentLoaded', repair);
                } else {
                    repair();
                }

                // Crossing the breakpoint sends it to the other home, and
                // core's own padding is not the same on both sides of it.
                narrow.addEventListener('change', function () {
                    place();
                    pull();
                });
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

            /*
             * The nesting limit, filtered, so the ⋮ menu can draw a paste that
             * would pass it as disabled rather than let it be pressed and
             * refused. The server asks the same filter again; this is the
             * affordance, not the rule.
             */
            'maxDepth' => (int) apply_filters('folderfolio_max_depth', FolderPath::MAX_DEPTH),

            /*
             * This person's own startup folder, and deliberately NOT the
             * site's. The toggle in the breadcrumb is pressed when *they*
             * chose this folder; a site-wide folder arriving is explained by
             * the sentence under the crumbs instead. If this carried the
             * effective value, pressing it while the site sent you here would
             * clear a preference you never set and leave you arriving in the
             * same place — a control that looks broken while working
             * correctly.
             *
             * In appConfig() and not config(): that one writes
             * window.folderFolioRail, which is the chrome script's own
             * globals — width, open, the drag's bounds. Everything the React
             * app reads is here.
             */
            'startupFolder' => RailPreferences::forUser(get_current_user_id())['startup'],

            /*
             * What another folder plugin is holding, when this library holds
             * nothing of ours — the one thing the rail's empty state needs in
             * order to stop saying something untrue.
             *
             * Server-rendered rather than fetched, so the correct sentence is
             * in the markup on first paint instead of replacing a wrong one a
             * moment later. It is null on almost every site and costs one
             * COUNT to find that out; Modules\Import\Elsewhere has the rest.
             */
            'elsewhere' => Elsewhere::forConfig(),
            'i18n' => [
                'folders' => __('Folders', 'folderfolio'),
                'newFolder' => __('New folder', 'folderfolio'),
                'save' => __('Save', 'folderfolio'),
                'cancel' => __('Cancel', 'folderfolio'),
                'retry' => __('Retry', 'folderfolio'),
                'allMedia' => __('All media', 'folderfolio'),
                'unassigned' => __('Unassigned', 'folderfolio'),
                'atTopLevel' => __('Top level', 'folderfolio'),

                /*
                 * The narrow-width level view — one folder's children at a
                 * time, with the way out named after where it goes rather
                 * than called "Back". On a sheet with no history stack, the
                 * useful word is the destination.
                 *
                 * translators: %s is the parent folder's name, or "Top level".
                 */
                'upToFolder' => __('Up to %s', 'folderfolio'),
                'noSubfolders' => __('Nothing inside this folder', 'folderfolio'),
                /*
                 * The row's whole sentence for assistive tech. The chevron
                 * that says "this goes somewhere" is decorative, so the words
                 * have to carry it.
                 *
                 * translators: 1: folder name, 2: how many files, 3: how many
                 * folders are inside it.
                 */
                'folderWithSubfolders' => __(
                    '%1$s, %2$s files, %3$s folders inside',
                    'folderfolio'
                ),
                'createAtRoot' => __('New folder at the top level', 'folderfolio'),
                'createInFolder' => __('New folder inside the selected folder', 'folderfolio'),
                'searchPlaceholder' => __('Search folders', 'folderfolio'),
                'folderActions' => __('Folder actions', 'folderfolio'),
                'rename' => __('Rename', 'folderfolio'),
                'renameFolder' => __('Rename folder', 'folderfolio'),
                'newFolderName' => __('Name for the new folder', 'folderfolio'),
                'delete' => __('Delete', 'folderfolio'),

                // Ten strings the rail drew from t()'s English fallbacks since
                // they shipped — reordering, Sort inside, expand / collapse
                // all and the crumb row's collapse — found on 23 Sep by
                // diffing every t() key under apps/rail against this map.
                'moveUp' => __('Move up', 'folderfolio'),
                'moveDown' => __('Move down', 'folderfolio'),
                'sortInside' => __('Sort inside', 'folderfolio'),
                'sortSubfolders' => __('Subfolders', 'folderfolio'),
                'sortFiles' => __('Files', 'folderfolio'),
                'sortSameAsEverywhere' => __('Same as everywhere', 'folderfolio'),
                'expandAll' => __('Expand all', 'folderfolio'),
                'collapseAll' => __('Collapse all', 'folderfolio'),
                'breadcrumb' => __('Folder path', 'folderfolio'),
                'showHiddenLevels' => __('Show the levels in between', 'folderfolio'),

                // Cut, copy and paste — tier 1 item 5.
                'cut' => __('Cut', 'folderfolio'),
                'copy' => __('Copy', 'folderfolio'),
                'copyWithFiles' => __('Copy with files', 'folderfolio'),
                /* translators: %s is the name of the folder waiting to be pasted. */
                'pasteHeldCut' => __('Paste “%s” · cut', 'folderfolio'),
                /* translators: %s is the name of the folder waiting to be pasted. */
                'pasteHeldCopy' => __('Paste “%s” · copy', 'folderfolio'),
                /* translators: %s is the name of the folder waiting to be pasted, with its files. */
                'pasteHeldCopyFiles' => __('Paste “%s” · with files', 'folderfolio'),
                'pasteInside' => __('Inside this folder', 'folderfolio'),
                'pasteBeside' => __('Beside this folder', 'folderfolio'),
                /* translators: %s is a folder name. */
                'cutAnnounce' => __('Cut “%s”. Paste it inside or beside another folder.', 'folderfolio'),
                /* translators: %s is a folder name. */
                'copyAnnounce' => __('Copied “%s”. Paste it inside or beside another folder.', 'folderfolio'),
                /* translators: %s is a folder name. */
                'pasted' => __('Pasted “%s”', 'folderfolio'),
                // The notice sheet — any write the server refuses.
                // `dismiss` is further down, with the startup-folder sentence
                // that uses it too.
                'actionFailed' => __('That could not be done.', 'folderfolio'),
                'sort' => __('Sort', 'folderfolio'),
                'sortNameAsc' => __('Name, A to Z', 'folderfolio'),
                'sortNameDesc' => __('Name, Z to A', 'folderfolio'),
                'sortNewest' => __('Newest first', 'folderfolio'),
                'sortOldest' => __('Oldest first', 'folderfolio'),
                'sortCustom' => __('Custom order', 'folderfolio'),
                'undo' => __('Undo', 'folderfolio'),
                /* translators: %s is the folder name. */
                'deleted' => __('Deleted “%s”', 'folderfolio'),
                /*
                 * Two forms, chosen by the count (`tn()`), and the count is of
                 * the files that are filed nowhere else — a file still in
                 * another folder does not move to Unassigned, and the toast
                 * used to say it did.
                 *
                 * translators: 1: folder name, 2: number of files, always 1.
                 */
                'deletedWithFile' => __(
                    'Deleted “%1$s” — %2$s file moved to Unassigned',
                    'folderfolio'
                ),
                /* translators: 1: folder name, 2: number of files. */
                'deletedWithFiles' => __(
                    'Deleted “%1$s” — %2$s files moved to Unassigned',
                    'folderfolio'
                ),
                'emptyTree' => __('No folders yet', 'folderfolio'),

                /*
                 * The other empty library: one that has been filed, somewhere
                 * else. The counts are phrased separately and placed into the
                 * body, because a template with "%s folders" baked into it
                 * cannot be made singular by any translator.
                 *
                 * translators: 1: the other plugin's name, 2: a folder count
                 * already phrased, 3: a file count already phrased.
                 */
                'emptyElsewhereTitle' => __(
                    'Your media is already filed — just not here.',
                    'folderfolio'
                ),
                'emptyElsewhereBody' => __(
                    '%1$s has %2$s holding %3$s. Bringing them over adds them to FolderFolio — nothing is moved, and nothing is removed from where it is now.',
                    'folderfolio'
                ),
                /* translators: %s is a number of folders. */
                'emptyElsewhereFolderOne' => __('%s folder', 'folderfolio'),
                /* translators: %s is a number of folders. */
                'emptyElsewhereFolderMany' => __('%s folders', 'folderfolio'),
                /* translators: %s is a number of files. */
                'emptyElsewhereFileOne' => __('%s file', 'folderfolio'),
                /* translators: %s is a number of files. */
                'emptyElsewhereFileMany' => __('%s files', 'folderfolio'),
                /* translators: %s is a number of other plugins. */
                'emptyElsewhereOtherOne' => __(
                    '%s other plugin has folders here too.',
                    'folderfolio'
                ),
                /* translators: %s is a number of other plugins. */
                'emptyElsewhereOtherMany' => __(
                    '%s other plugins have folders here too.',
                    'folderfolio'
                ),
                'emptyElsewhereAction' => __('Review the import', 'folderfolio'),
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
                /*
                 * The toolbar trigger, separate from the label above because
                 * only this one opens something. The ellipsis is the
                 * convention for "asks before it acts" — `Apply` sits next to
                 * it in list mode and does not ask. Translators: keep or drop
                 * the ellipsis to match your language's own convention for a
                 * command that opens a dialog.
                 */
                'addToFolderOpens' => __('Add to folder…', 'folderfolio'),
                'moveToFolder' => __('Move to folder', 'folderfolio'),
                // The verb switch inside the flyout. Short, because they sit
                // in a segmented pair reading "Add to | Move to" above a list
                // of folders that completes the sentence.
                'folderAction' => __('What to do with the selection', 'folderfolio'),
                // Core's own wording for the same field, so the placeholder and
                // the screen-reader label do not disagree.
                'searchMedia' => __('Search media', 'folderfolio'),
                /*
                 * The narrow-width disclosure that stands for media type,
                 * date and folder once they no longer fit on one line.
                 *
                 * Plural. It opens three of them, and the singular also
                 * collided with core's own `Filter` submit button in list
                 * mode, which sits a few pixels away inside the same form —
                 * two adjacent controls with the same word on them, one
                 * disclosing and one submitting.
                 */
                'filters' => __('Filters', 'folderfolio'),
                /*
                 * The badge's sentence, which is the only form of it assistive
                 * tech gets — the number beside the button is aria-hidden,
                 * because "2" on its own is not a sentence. A plural pair
                 * rather than one string: with one filter set it announced
                 * "1 filters active", which is the count at which this badge
                 * appears most often.
                 *
                 * translators: %s is how many filters are currently set.
                 */
                'filterActive' => __('%s filter active', 'folderfolio'),
                'filtersActive' => __('%s filters active', 'folderfolio'),
                'verbAdd' => __('Add to', 'folderfolio'),
                'verbMove' => __('Move to', 'folderfolio'),
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
                'clearFilter' => __('Clear filter', 'folderfolio'),
                'startHere' => __('Start here', 'folderfolio'),
                'startHereHint' => __('Open the media library in this folder', 'folderfolio'),
                'dismiss' => __('Dismiss', 'folderfolio'),
                'startupFolderNote' => __('The media library opens in this folder. Clear the filter in the path above to see everything.', 'folderfolio'),
                /* translators: 1: number of folders, 2: the folder they are in. */
                'folderIn' => __('%1$s folder in %2$s', 'folderfolio'),
                /* translators: 1: number of folders, 2: the folder they are in. */
                'foldersIn' => __('%1$s folders in %2$s', 'folderfolio'),
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
