<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Database\Schema;
use FolderFolio\Database\StatusReport;
use FolderFolio\Domain\AttachmentFolderRepository;
use FolderFolio\Support\Assets;
use FolderFolio\Domain\FolderService;
use FolderFolio\Domain\FolderTree;
use FolderFolio\Support\PostTypes;
use FolderFolio\Support\Settings;

/**
 * The FolderFolio settings screen — screen 08.
 *
 * One page, three tabs: Settings, Import, Status. Not three menu entries: a
 * plugin whose whole job is one media-library panel does not get four rows in
 * the sidebar, and the three things here are read in sequence by the same
 * person on the same afternoon.
 *
 * No React. The rail is an app because it is a live tree over a REST API; this
 * is a form with eleven controls that is opened twice in a site's lifetime.
 * A build step, a hydration pass and a REST round-trip to change a number in a
 * text field would be machinery with nothing to do. It posts to
 * admin-post.php, it redirects, it shows a notice — the pattern every
 * WordPress admin already knows how to debug.
 *
 * ## Deltas from the design board
 *
 * 1. The roles matrix is editable. Screen 08 draws ●/○ glyphs; the blurb
 *    beside them ("Every role, every permission") only means anything
 *    if the table decides something, so the glyphs are checkboxes. A tick is
 *    an input a screen reader can announce and a keyboard can reach, which a
 *    ● is not.
 * 2. Status's first button repairs the folder tree; it does not rebuild
 *    counts. Counts are computed from the assignments table on every read —
 *    there is no cache to rebuild, and a button that runs nothing is worse
 *    than no button. Its place is taken by the one that clears entries for
 *    files that no longer exist, which is the repair the Doctor findings
 *    actually call for. Both were named after their mechanism until 21 Sep
 *    ("Rebuild paths", "Remove orphaned rows"); they are named after their
 *    outcome now.
 * 3. The Import tab lists detected sources and hands off to the existing
 *    importer. The four-step wizard is screen 07 and is built next; the tab is
 *    where it lands.
 */
final class SettingsPage
{
    /**
     * Slug of the page and of the top-level menu.
     *
     * Not `folderfolio-import`, which is what v0.2.0's single screen used:
     * that page is now one tab of this one, and a slug naming the tab would be
     * wrong the moment Settings became the landing tab.
     */
    public const SLUG = 'folderfolio';

    public const SAVE_ACTION = 'folderfolio_save_settings';

    public const TOOL_ACTION = 'folderfolio_run_tool';

    /** @var list<string> */
    private const TABS = ['settings', 'import', 'status'];

    private string $hookSuffix = '';

    public function __construct(
        private readonly ImportPage $import,
        private readonly FolderService $folders = new FolderService()
    ) {
    }

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handleSave']);
        add_action('admin_post_' . self::TOOL_ACTION, [$this, 'handleTool']);
    }

    /**
     * Told where it ended up, by whoever registered the menu. See Menu.
     */
    public function setHookSuffix(string $hookSuffix): void
    {
        $this->hookSuffix = $hookSuffix;
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if ('' === $this->hookSuffix || $hookSuffix !== $this->hookSuffix) {
            return;
        }

        $style = 'assets/build/core/settings.css';

        if (file_exists(FOLDERFOLIO_PLUGIN_DIR . $style)) {
            wp_enqueue_style(
                'folderfolio-settings',
                FOLDERFOLIO_PLUGIN_URL . $style,
                [],
                Assets::version($style)
            );
        }

        // Only the Status tab has anything for a script to do, and all of it
        // is the clipboard. Everything else on the page works with JavaScript
        // off, including the report itself — it is in a textarea, which can be
        // selected by hand.
        $script = 'assets/build/core/settings.js';

        if ('status' === $this->currentTab() && file_exists(FOLDERFOLIO_PLUGIN_DIR . $script)) {
            wp_enqueue_script(
                'folderfolio-settings',
                FOLDERFOLIO_PLUGIN_URL . $script,
                [],
                Assets::version($script),
                ['in_footer' => true, 'strategy' => 'defer']
            );
        }

        // The importer's own assets still belong to the importer.
        if ('import' === $this->currentTab()) {
            $this->import->enqueueTabAssets();
        }
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage FolderFolio settings.', 'folderfolio'));
        }

        $tab = $this->currentTab();

        ?>
        <div class="wrap folderfolio">
            <div class="folderfolio-settings">
                <div class="folderfolio-settings__head">
                    <?php
                    /*
                     * The h1 is here rather than directly inside .wrap because
                     * core's common.js moves admin notices to just after the
                     * first heading — so this is what puts "Settings saved."
                     * inside the card, under the title, instead of above it
                     * where it would be a notice about a form it is not
                     * touching.
                     */
                    ?>
                    <h1 class="folderfolio-settings__title">FolderFolio</h1>

                    <?php $this->renderNotice(); ?>

                    <nav class="folderfolio-settings__tabs" aria-label="<?php esc_attr_e('FolderFolio settings sections', 'folderfolio'); ?>">
                        <?php foreach ($this->tabs() as $slug => $label) : ?>
                            <a
                                class="folderfolio-tab"
                                href="<?php echo esc_url($this->tabUrl($slug)); ?>"
                                <?php echo $slug === $tab ? 'aria-current="page"' : ''; ?>
                            ><?php echo esc_html($label); ?></a>
                        <?php endforeach; ?>
                    </nav>
                </div>

                <div class="folderfolio-settings__body">
                    <?php
                    if ('import' === $tab) {
                        $this->import->renderTab();
                    } elseif ('status' === $tab) {
                        $this->renderStatusTab();
                    } else {
                        $this->renderSettingsTab();
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * @return array<string, string>
     */
    private function tabs(): array
    {
        return [
            'settings' => __('Settings', 'folderfolio'),
            'import' => __('Import', 'folderfolio'),
            'status' => __('Status', 'folderfolio'),
        ];
    }

    private function currentTab(): string
    {
        // Read-only navigation, so no nonce: there is nothing here to protect.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : 'settings';

        return in_array($tab, self::TABS, true) ? $tab : 'settings';
    }

    private function tabUrl(string $tab): string
    {
        return add_query_arg(
            ['page' => self::SLUG, 'tab' => $tab],
            admin_url('admin.php')
        );
    }

    // -----------------------------------------------------------------------
    // Settings tab
    // -----------------------------------------------------------------------

    private function renderSettingsTab(): void
    {
        $settings = Settings::get();

        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
            <?php wp_nonce_field(self::SAVE_ACTION); ?>

            <?php $this->renderPostTypes($settings['post_types']); ?>

            <div class="folderfolio-field">
                <div class="folderfolio-field__label">
                    <div class="folderfolio-field__name"><?php esc_html_e('Folder counts', 'folderfolio'); ?></div>
                    <div class="folderfolio-field__note"><?php esc_html_e('How a folder counts what is in it', 'folderfolio'); ?></div>
                </div>
                <div class="folderfolio-field__control">
                    <div class="folderfolio-seg" role="group" aria-label="<?php esc_attr_e('Folder counts', 'folderfolio'); ?>">
                        <?php
                        $modes = [
                            'inherited' => __('Inherited', 'folderfolio'),
                            'direct' => __('Direct only', 'folderfolio'),
                        ];

                        foreach ($modes as $value => $label) :
                            $id = 'folderfolio-count-' . $value;
                            ?>
                            <input
                                type="radio"
                                id="<?php echo esc_attr($id); ?>"
                                name="count_mode"
                                value="<?php echo esc_attr($value); ?>"
                                <?php checked($settings['count_mode'], $value); ?>
                            >
                            <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
                        <?php endforeach; ?>
                    </div>
                    <div class="folderfolio-field__help">
                        <?php
                        /*
                         * Both options, and the one in force leads.
                         *
                         * This line used to describe *Inherited* only, so on a
                         * site set to *Direct only* — which is the default —
                         * it argued for the choice you had just declined and
                         * never described the one actually running. Describing
                         * only the active option instead would fail the other
                         * way: you cannot choose between two things when you
                         * are shown one of them.
                         *
                         * So both, ordered. Order carries the emphasis, which
                         * costs no script — a help line that re-writes itself
                         * on a click would be describing a setting that has
                         * not been saved yet.
                         */
                        $descriptions = [
                            'inherited' => [
                                __('Inherited', 'folderfolio'),
                                __('counts everything inside a folder, subfolders included.', 'folderfolio'),
                            ],
                            'direct' => [
                                __('Direct only', 'folderfolio'),
                                __('counts just what is filed in the folder itself.', 'folderfolio'),
                            ],
                        ];

                        $active = isset($descriptions[$settings['count_mode']])
                            ? (string) $settings['count_mode']
                            : 'inherited';

                        foreach ([$active, 'inherited' === $active ? 'direct' : 'inherited'] as $mode) :
                            [$name, $sentence] = $descriptions[$mode];
                            ?>
                            <strong><?php echo esc_html($name); ?></strong>
                            <?php echo esc_html($sentence); ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="folderfolio-field">
                <div class="folderfolio-field__label">
                    <label class="folderfolio-field__name" for="folderfolio-default-sort">
                        <?php esc_html_e('Default sort', 'folderfolio'); ?>
                    </label>
                    <div class="folderfolio-field__note"><?php esc_html_e('The order folders open in', 'folderfolio'); ?></div>
                </div>
                <div class="folderfolio-field__control">
                    <select id="folderfolio-default-sort" name="default_sort">
                        <?php foreach ($this->sortLabels() as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['default_sort'], $value); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="folderfolio-field">
                <div class="folderfolio-field__label">
                    <label class="folderfolio-field__name" for="folderfolio-startup-folder">
                        <?php esc_html_e('Opens in', 'folderfolio'); ?>
                    </label>
                    <div class="folderfolio-field__note">
                        <?php esc_html_e('The folder the media library starts in', 'folderfolio'); ?>
                    </div>
                </div>
                <div class="folderfolio-field__control">
                    <select id="folderfolio-startup-folder" name="startup_folder">
                        <?php
                        /*
                         * The empty value is "no startup folder", and it is
                         * not the same as Unassigned below it — which is a
                         * real destination, and the one somebody whose job is
                         * filing media actually wants to arrive at.
                         */
                        ?>
                        <option value="" <?php selected($settings['startup_folder'], null); ?>>
                            <?php esc_html_e('All media — no starting folder', 'folderfolio'); ?>
                        </option>
                        <option value="0" <?php selected($settings['startup_folder'], 0); ?>>
                            <?php esc_html_e('Unassigned — files in no folder', 'folderfolio'); ?>
                        </option>
                        <?php
                        /*
                         * A stored folder that has since been deleted has no
                         * option here, so the browser falls back to the first
                         * one and the field reads "no starting folder".
                         *
                         * Left that way deliberately. It is what will actually
                         * happen — `Admin\StartupFolder` checks the folder
                         * exists and does not redirect when it does not — so
                         * the field is describing the behaviour rather than
                         * the stored number, and the next save tidies the
                         * option up. The alternative, an option reading
                         * "folder 412 (deleted)", is a row about a thing that
                         * is gone on a screen for things you set.
                         */
                        ?>
                        <?php foreach ($this->folderOptions() as $option) : ?>
                            <option
                                value="<?php echo esc_attr((string) $option['id']); ?>"
                                <?php selected($settings['startup_folder'], $option['id']); ?>
                            >
                                <?php
                                // Indent with real spaces rather than a CSS
                                // rule: an <option>'s text is one run and a
                                // stylesheet cannot reach inside it.
                                echo esc_html(str_repeat("\u{00a0}\u{00a0}\u{00a0}", $option['depth']) . $option['name']);
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="folderfolio-field__help">
                        <?php
                        esc_html_e(
                            // Since 720ae7f the crumb row ends in two labelled
                            // buttons, not a ×, and a person's own starting
                            // folder (Start here) beats this one — the sentence
                            // said neither until 24 Sep.
                            'Where the media library opens, unless someone has chosen their own starting folder with Start here. The breadcrumb names the folder, with Clear filter beside it, and the address bar carries it — so nobody is looking at a filtered library without being told.',
                            'folderfolio'
                        );
                        ?>
                    </p>
                </div>
            </div>

            <?php
            /*
             * `--last` closes the group with a 2px rule before the roles
             * matrix. It sat on the Folder colours row until 21 Sep, when that
             * row was removed: ten swatches nobody can change, on the tab for
             * things you change. A folder's colour is picked on the folder, in
             * the rail's More menu, where the ten are already shown by name —
             * and the palette is fixed on purpose, because a folder stores a
             * swatch name and the hex it resolves to is a property of the
             * admin colour scheme (see Support\Swatches).
             */
            ?>
            <div class="folderfolio-field folderfolio-field--last">
                <div class="folderfolio-field__label">
                    <label class="folderfolio-field__name" for="folderfolio-undo-window">
                        <?php esc_html_e('Undo window', 'folderfolio'); ?>
                    </label>
                </div>
                <div class="folderfolio-field__control folderfolio-field__inline">
                    <input
                        type="number"
                        id="folderfolio-undo-window"
                        name="undo_window"
                        value="<?php echo esc_attr((string) $settings['undo_window']); ?>"
                        min="<?php echo esc_attr((string) Settings::MIN_UNDO); ?>"
                        max="<?php echo esc_attr((string) Settings::MAX_UNDO); ?>"
                        step="1"
                    >
                    <span><?php esc_html_e('seconds before a delete is final', 'folderfolio'); ?></span>
                </div>
            </div>

            <?php $this->renderMatrix($settings['roles']); ?>

            <p>
                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Save changes', 'folderfolio'); ?>
                </button>
            </p>
        </form>
        <?php
    }

    /**
     * Folders for — tier 3 item 12.
     *
     * Media is shown ticked and cannot be cleared: its folders are the
     * product, not an option, and a box that did nothing when cleared would
     * be a lie. The blank hidden entry makes "every box cleared" a value the
     * form can send, rather than an absent key that would read as the
     * defaults. A type saved while its plugin was active but not registered
     * now is carried through unseen, so switching a plugin off for a week and
     * saving this screen does not forget it.
     *
     * @param list<string> $chosen
     */
    private function renderPostTypes(array $chosen): void
    {
        $offered = PostTypes::offered();

        ?>
        <div class="folderfolio-field">
            <div class="folderfolio-field__label">
                <div class="folderfolio-field__name" id="folderfolio-post-types-name"><?php esc_html_e('Folders for', 'folderfolio'); ?></div>
                <div class="folderfolio-field__note"><?php esc_html_e('Which screens have a folder tree', 'folderfolio'); ?></div>
            </div>
            <div class="folderfolio-field__control">
                <input type="hidden" name="post_types[]" value="">
                <div class="folderfolio-checks" role="group" aria-labelledby="folderfolio-post-types-name">
                    <label class="folderfolio-checks__item">
                        <input type="checkbox" checked disabled>
                        <?php esc_html_e('Media', 'folderfolio'); ?>
                    </label>
                    <?php foreach ($offered as $slug => $label) : ?>
                        <label class="folderfolio-checks__item">
                            <input
                                type="checkbox"
                                name="post_types[]"
                                value="<?php echo esc_attr($slug); ?>"
                                <?php checked(in_array($slug, $chosen, true)); ?>
                            >
                            <?php echo esc_html($label); ?>
                        </label>
                    <?php endforeach; ?>
                    <?php foreach (array_diff($chosen, array_keys($offered)) as $kept) : ?>
                        <input type="hidden" name="post_types[]" value="<?php echo esc_attr($kept); ?>">
                    <?php endforeach; ?>
                </div>
                <p class="folderfolio-field__help">
                    <?php
                    esc_html_e(
                        'Each one gets its own folders, on its own list screen and in the editor. A post and an image never share a folder.',
                        'folderfolio'
                    );
                    ?>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * @return array<string, string>
     */
    private function sortLabels(): array
    {
        return [
            'name-asc' => __('Name, A to Z', 'folderfolio'),
            'name-desc' => __('Name, Z to A', 'folderfolio'),
            'newest' => __('Newest first', 'folderfolio'),
            'oldest' => __('Oldest first', 'folderfolio'),
            // Last, and after the four views: this one is the tree's own
            // arrangement rather than an order imposed on it.
            'custom' => __('Custom order', 'folderfolio'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function abilityLabels(): array
    {
        return [
            'create' => __('Create', 'folderfolio'),
            // Not "Rename". The key is still `rename` and every stored role
            // option is untouched — this is the column heading only. What it
            // now permits is rename, move, reorder, per-folder sort and
            // cut/paste, which is one idea: restructure the tree.
            'rename' => __('Organise', 'folderfolio'),
            'delete' => __('Delete', 'folderfolio'),
            'assign' => __('Assign files', 'folderfolio'),
            // Tier 2 item 10: lock and unlock, and not be stopped by a lock.
            'lock' => __('Lock', 'folderfolio'),
            'download' => __('Download', 'folderfolio'),
        ];
    }

    /**
     * @param array<string, list<string>> $matrix
     */
    private function renderMatrix(array $matrix): void
    {
        $abilities = $this->abilityLabels();

        ?>
        <div class="folderfolio-matrix-wrap">
            <div class="folderfolio-field__name"><?php esc_html_e('Who can manage folders', 'folderfolio'); ?></div>
            <div class="folderfolio-field__help folderfolio-matrix-wrap__lede">
                <?php esc_html_e('Every role, every permission. Free, and staying free.', 'folderfolio'); ?>
            </div>

            <table class="folderfolio-matrix">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Role', 'folderfolio'); ?></th>
                        <?php foreach ($abilities as $label) : ?>
                            <th scope="col"><?php echo esc_html($label); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->roles() as $role => $name) : ?>
                        <?php
                        $pinned = 'administrator' === $role;
                        $granted = $matrix[$role] ?? Settings::defaultRoles()[$role] ?? [];
                        ?>
                        <tr class="<?php echo $pinned ? 'folderfolio-matrix__pinned' : ''; ?>">
                            <th scope="row" class="folderfolio-matrix__role"><?php echo esc_html($name); ?></th>
                            <?php foreach ($abilities as $ability => $label) : ?>
                                <td>
                                    <?php // A label, so the checkbox has a visible name where the matrix stacks below 640px and the column heads are gone. ?>
                                    <label class="folderfolio-matrix__cell">
                                    <input
                                        type="checkbox"
                                        name="roles[<?php echo esc_attr($role); ?>][<?php echo esc_attr($ability); ?>]"
                                        value="1"
                                        <?php checked($pinned || in_array($ability, $granted, true)); ?>
                                        <?php disabled($pinned); ?>
                                        aria-label="<?php
                                            echo esc_attr(sprintf(
                                                /* translators: two phrases joined by a dash: 1: a file's path inside the download and why it was left out, or an ability and a role. */
                                                __('%1$s — %2$s', 'folderfolio'),
                                                $label,
                                                $name
                                            ));
                                        ?>"
                                    >
                                    <span class="folderfolio-matrix__ability" aria-hidden="true"><?php echo esc_html($label); ?></span>
                                    </label>
                                    <?php if ($pinned) : ?>
                                        <?php // Disabled inputs post nothing, and a matrix that lost its administrator row on save would lock the site out of this screen. ?>
                                        <input type="hidden" name="roles[<?php echo esc_attr($role); ?>][<?php echo esc_attr($ability); ?>]" value="1">
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="folderfolio-matrix__why">
                <?php esc_html_e('This table can only narrow WordPress’s own permissions — it never widens them. Administrators always have every folder permission. Everyone else needs a tick here and WordPress’s own permission for that screen: uploading files for media, editing posts for posts, editing pages for pages. On those screens, Assign files means filing posts and pages.', 'folderfolio'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Every role on the site, administrator first.
     *
     * Read from wp_roles() rather than hard-coded, so a site with Shop Manager
     * or a membership plugin's roles can set them here instead of discovering
     * that the table only knows about the five that ship with WordPress.
     *
     * @return array<string, string>
     */
    private function roles(): array
    {
        $roles = [];
        $names = wp_roles()->get_names();

        // translate_user_role() is what the Users screen uses; without it the
        // role names are the only untranslated strings on a translated page.
        foreach ($names as $slug => $name) {
            $roles[(string) $slug] = translate_user_role((string) $name);
        }

        if (isset($roles['administrator'])) {
            $administrator = $roles['administrator'];
            unset($roles['administrator']);
            $roles = ['administrator' => $administrator] + $roles;
        }

        return $roles;
    }

    // -----------------------------------------------------------------------
    // Status tab
    // -----------------------------------------------------------------------

    private function renderStatusTab(): void
    {
        $report = new StatusReport();

        ?>
        <div class="folderfolio-field__name"><?php esc_html_e('Health check', 'folderfolio'); ?></div>
        <div class="folderfolio-field__help folderfolio-status__lede">
            <?php esc_html_e('What FolderFolio has stored on this site, and the repairs to run if anything has drifted out of step. Nothing here changes until you press one.', 'folderfolio'); ?>
        </div>

        <table class="folderfolio-status">
            <tbody>
                <?php foreach ($report->rows() as $row) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($row['label']); ?></th>
                        <td class="<?php echo $row['bad'] ? 'folderfolio-status__bad' : ''; ?>">
                            <?php echo esc_html($row['value']); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="folderfolio-tools">
            <?php foreach ($this->tools() as $tool => $label) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::TOOL_ACTION); ?>">
                    <input type="hidden" name="tool" value="<?php echo esc_attr($tool); ?>">
                    <?php wp_nonce_field(self::TOOL_ACTION); ?>
                    <button type="submit" class="button"><?php echo esc_html($label); ?></button>
                </form>
            <?php endforeach; ?>

            <?php
            /*
             * The confirmation label is handed over from here rather than
             * written in the bundle. settings.ts falls back to a literal
             * 'Copied', and until 21 Sep nothing set this attribute — so the
             * fallback always won and the one word the button says after you
             * press it was the only string in the plugin that `make-pot`
             * could not see.
             */
            ?>
            <button
                type="button"
                class="button"
                data-folderfolio-copy="#folderfolio-report"
                data-folderfolio-copied="<?php esc_attr_e('Copied', 'folderfolio'); ?>"
            >
                <?php esc_html_e('Copy report', 'folderfolio'); ?>
            </button>
        </div>

        <label class="screen-reader-text" for="folderfolio-report">
            <?php esc_html_e('Status report', 'folderfolio'); ?>
        </label>
        <textarea id="folderfolio-report" class="folderfolio-report" rows="10" readonly><?php
            echo esc_textarea($report->text());
        ?></textarea>
        <?php
    }

    /**
     * @return array<string, string>
     */
    private function tools(): array
    {
        return [
            /*
             * Named for the outcome, not the mechanism — and short enough to
             * survive the row. "Repair the folder tree" and "Clear out entries
             * for missing files" read better still, and at 521px of viewport
             * the three controls came to 525.8px in a 449px content box and
             * pushed 56px of the page off screen. The row is `auto auto 1fr`,
             * so it does not wrap to tell you: it overflows.
             */
            'rebuild-paths' => __('Repair folder tree', 'folderfolio'),
            'remove-orphans' => __('Forget deleted files', 'folderfolio'),
        ];
    }

    // -----------------------------------------------------------------------
    // Form handling
    // -----------------------------------------------------------------------

    /**
     * Every folder, in display order, with the depth to indent it by.
     *
     * A `<select>` rather than the rail's own picker, because this screen is
     * plain PHP and always has been — eleven controls posted once, against a
     * wizard that watches the server. The cost is honest and worth stating:
     * on the 1,053-folder stress site this is 1,053 options, about 40KB of
     * markup on a page nobody opens often. A searchable React control here
     * would be a second folder picker to keep in step with the two that exist.
     *
     * @return list<array{id: int, name: string, depth: int}>
     */
    private function folderOptions(): array
    {
        $options = [];

        FolderTree::walk(
            $this->folders->tree(),
            static function (array $node, int $depth) use (&$options): void {
                $options[] = [
                    'id' => (int) $node['id'],
                    'name' => (string) $node['name'],
                    'depth' => $depth,
                ];
            }
        );

        return $options;
    }

    public function handleSave(): void
    {
        $this->guard(self::SAVE_ACTION);

        // Sanitising is Settings::sanitize()'s job, and it is the same
        // function the REST and WP-CLI paths would use. Passing the raw array
        // in is deliberate: one place decides what a valid value is.
        /** @var array<string, mixed> $raw */
        $raw = wp_unslash($_POST); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- guard() checked the nonce; Settings::save() sanitises

        Settings::save($raw);

        $this->redirect('settings', 'saved');
    }

    public function handleTool(): void
    {
        $this->guard(self::TOOL_ACTION);

        $tool = isset($_POST['tool']) ? sanitize_key(wp_unslash((string) $_POST['tool'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() checked the nonce

        if ('rebuild-paths' === $tool) {
            (new Schema())->backfillPaths(true);

            $this->redirect('status', 'paths');
        }

        if ('remove-orphans' === $tool) {
            (new AttachmentFolderRepository())->deleteOrphans();

            $this->redirect('status', 'orphans');
        }

        $this->redirect('status');
    }

    private function guard(string $action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage FolderFolio settings.', 'folderfolio'));
        }

        check_admin_referer($action);
    }

    /**
     * Post, redirect, get. A settings screen that re-renders on POST is a
     * settings screen that re-saves on refresh.
     */
    private function redirect(string $tab, string $done = ''): void
    {
        $args = ['page' => self::SLUG, 'tab' => $tab];

        if ('' !== $done) {
            $args['folderfolio-done'] = $done;
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));

        exit;
    }

    private function renderNotice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $done = isset($_GET['folderfolio-done'])
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? sanitize_key(wp_unslash((string) $_GET['folderfolio-done']))
            : '';

        $messages = [
            'saved' => __('Settings saved.', 'folderfolio'),
            'paths' => __('Folder tree repaired — every folder agrees with its parent again.', 'folderfolio'),
            'orphans' => __('Entries for files that no longer exist have been cleared out.', 'folderfolio'),
        ];

        if (!isset($messages[$done])) {
            return;
        }

        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html($messages[$done])
        );
    }
}
