<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Domain\AttachmentFolderRepository;
use FolderFolio\Domain\FolderPath;
use FolderFolio\Domain\FolderRepository;
use FolderFolio\Support\Capabilities;
use FolderFolio\Support\PostTypes;

/**
 * The Folders column in the Media Library's list table — screen 06.
 *
 * It is the **only** column FolderFolio adds, and the screen's own caption
 * says why it earns its place: in list mode the drill-down happens here.
 * Grid mode has the cards; the table has this.
 *
 * ## What a cell says
 *
 * Every folder the file is in, as a full path:
 *
 *     Brand / Logos / Primary · Campaigns / Spring 2026
 *
 * Paths here, not the indentation the filter-row select uses, because a table
 * cell has no hierarchy to indent inside — the path is the only thing that
 * distinguishes two folders called "Primary". Membership is many-to-many, so
 * a file can be in several; they are separated rather than stacked so the
 * row keeps its height.
 *
 * ## Two queries, not two per row
 *
 * `manage_media_custom_column` fires once per row, and the obvious
 * implementation asks the database per file. Twenty rows is twenty queries,
 * on a screen that can answer the whole question at once. So the first cell
 * primes every attachment on the page from `$wp_query` — one query for the
 * assignments, one for the folder names — and every cell after it is a lookup
 * in an array.
 *
 * The folder read is the whole table rather than the folders in play, because
 * a path is rendered from its ancestors' names and those ancestors need not
 * be on this page. A folder tree is a few dozen rows; there is no version of
 * this worth paginating.
 *
 * ## Why the links are real links
 *
 * Clicking one filters the library without leaving the page — `watchFolderLinks`
 * in `assets/src/lib/filter.ts` intercepts the click and hands it to the same
 * store the rail uses. But the markup is an `<a href>` with the folder in the
 * query string, so it still works with scripts off, middle-clicks into a new
 * tab, and shows its destination in the status bar. The same arrangement as
 * the filter-row select, for the same reason.
 */
final class FoldersColumn
{
    public const COLUMN = 'folderfolio_folders';

    /**
     * Folder rows by id: `['name' => string, 'path' => string]`.
     *
     * Null until the first cell primes it.
     *
     * @var array<int, array{name: string, path: string}>|null
     */
    private ?array $folders = null;

    /**
     * Folder ids by attachment id, for the attachments on this page.
     *
     * @var array<int, list<int>>
     */
    private array $memberships = [];

    /** Attachment ids already primed, so a second miss does not re-query. */
    private bool $primed = false;

    public function __construct(
        private readonly AttachmentFolderRepository $assignments = new AttachmentFolderRepository(),
        private readonly FolderRepository $folderRepository = new FolderRepository()
    ) {
    }

    /** Whose folders the cells name — media, or the list screen's post type. */
    private string $objectType = PostTypes::MEDIA;

    public function register(): void
    {
        add_filter('manage_media_columns', [$this, 'addColumn']);
        add_action('manage_media_custom_column', [$this, 'renderCell'], 10, 2);

        // A post type's list screen — tier 3 item 12. Hooked once the screen
        // knows its type, and only for a type with folders this person can
        // reach: the column is part of the folder feature, not a column every
        // list screen grows.
        add_action('load-edit.php', [$this, 'registerForPostType']);
    }

    public function registerForPostType(): void
    {
        global $typenow;

        $type = is_string($typenow) && '' !== $typenow ? $typenow : 'post';

        if (PostTypes::MEDIA === $type || !Capabilities::canUseFolders($type)) {
            return;
        }

        $this->objectType = $type;

        add_filter("manage_{$type}_posts_columns", [$this, 'addColumn']);
        add_action("manage_{$type}_posts_custom_column", [$this, 'renderCell'], 10, 2);
    }

    /**
     * Insert Folders after File.
     *
     * Appending would put it after Date, at the far right, where the design
     * does not have it and where nobody reads. Core's media columns are
     * `cb, title, author, parent, comments, date`; this rebuilds the array in
     * order rather than using array_splice, because the keys are meaningful
     * and array_splice throws them away.
     *
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function addColumn($columns): array
    {
        $columns = (array) $columns;
        $out = [];

        foreach ($columns as $key => $label) {
            $out[$key] = $label;

            if ('title' === $key) {
                $out[self::COLUMN] = $this->headerLabel();
            }
        }

        // No `title` column — a plugin removed it, or a future core changed
        // its key. Better at the end than not at all.
        if (!isset($out[self::COLUMN])) {
            $out[self::COLUMN] = $this->headerLabel();
        }

        return $out;
    }

    /**
     * The header label, wrapped so it can be coloured.
     *
     * WordPress prints a column's display name unescaped, into the `<th>` and
     * again into the Screen Options checkbox — which is why the accent rule in
     * _content.css is scoped to inside `.wp-list-table`, and why the wrapper
     * carries the bare `folderfolio` class: the `<th>` is core's markup, and
     * `--ff-*` resolves nowhere outside that class.
     *
     * ## Why not simply "Folders"
     *
     * Premio's Folders registers its taxonomy with `show_admin_column`, so on
     * a site running both, this table grows two adjacent columns headed
     * *Folders* and there is no way to tell which is which. Making the header
     * conditional would mean detecting a rival at runtime, which this plugin
     * does not do anywhere and should not start doing here.
     *
     * So the label is distinct by construction. **"Media folders"** rather
     * than the product's name: the rail's eyebrow carries *FolderFolio*
     * deliberately, but a table header is read on every row of every media
     * library, and a product name there is noise for the majority of sites
     * that have no second folder plugin at all. A plain noun phrase costs them
     * nothing and still cannot be confused with a bare *Folders*.
     */
    private function headerLabel(): string
    {
        return '<span class="folderfolio folderfolio-folders__head">'
            . esc_html(
                PostTypes::MEDIA === $this->objectType
                    ? __('Media folders', 'folderfolio')
                    : __('Folders', 'folderfolio')
            )
            . '</span>';
    }

    /**
     * @param string $column
     * @param int    $attachmentId
     */
    public function renderCell($column, $attachmentId): void
    {
        if (self::COLUMN !== (string) $column) {
            return;
        }

        $this->prime();

        $folderIds = $this->memberships[(int) $attachmentId] ?? [];
        $paths = [];

        foreach ($folderIds as $folderId) {
            $label = $this->humanPath($folderId);

            if ('' !== $label) {
                $paths[$folderId] = $label;
            }
        }

        echo '<span class="folderfolio folderfolio-folders">';

        if ($paths === []) {
            // Core's own convention for an empty cell. Not a link to
            // Unassigned: a dash that filters the library is a surprise, and
            // the rail has a row for it that says what it is.
            echo '<span aria-hidden="true">&#8212;</span>';
            echo '<span class="screen-reader-text">'
                . esc_html__('Not in any folder', 'folderfolio')
                . '</span></span>';

            return;
        }

        $first = true;

        foreach ($paths as $folderId => $label) {
            if (!$first) {
                echo '<span class="folderfolio-folders__sep" aria-hidden="true">&middot;</span>';
            }

            $first = false;

            printf(
                '<a class="folderfolio-folders__link" href="%1$s" data-folderfolio-folder="%2$d">%3$s</a>',
                esc_url(
                    add_query_arg(
                        [MediaLibraryFilter::QUERY_VAR => $folderId],
                        PostTypes::MEDIA === $this->objectType
                            ? admin_url('upload.php')
                            : add_query_arg('post_type', $this->objectType, admin_url('edit.php'))
                    )
                ),
                $folderId,
                esc_html($label)
            );
        }

        echo '</span>';
    }

    /**
     * Read everything this page needs, once.
     *
     * `$wp_query->posts` is the list table's own result set — the same rows it
     * is about to render — so priming from it asks about exactly the right
     * attachments and no others.
     */
    private function prime(): void
    {
        if ($this->primed) {
            return;
        }

        $this->primed = true;

        $query = $GLOBALS['wp_query'] ?? null;
        $posts = ($query instanceof \WP_Query) ? $query->posts : [];

        $ids = [];

        foreach ((array) $posts as $post) {
            if ($post instanceof \WP_Post) {
                $ids[] = $post->ID;
            } elseif (is_object($post) && isset($post->ID)) {
                // 'fields' => 'id=>parent' — how edit.php queries a
                // hierarchical type such as Pages: plain objects with ID and
                // post_parent, and every page on the site rather than one
                // screenful, because core builds the hierarchy in PHP.
                $ids[] = (int) $post->ID;
            } elseif (is_numeric($post)) {
                // 'fields' => 'ids' — not how upload.php queries, but a filter
                // can make it so, and a fatal here would take the screen down.
                $ids[] = (int) $post;
            }
        }

        if ($ids !== []) {
            $this->memberships = $this->assignments->folderIdsForAttachments($ids);
        }

        $this->folders = [];

        foreach ($this->folderRepository->all($this->objectType) as $row) {
            $this->folders[(int) $row['id']] = [
                'name' => (string) $row['name'],
                'path' => (string) $row['path'],
            ];
        }
    }

    /**
     * `Brand / Logos / Primary`, built from the materialised path.
     *
     * The path already holds the ancestor ids in order — that is what it is
     * for — so this is array lookups, not queries. An id the folder map does
     * not have is skipped rather than rendered as a gap: it means a row was
     * deleted between the two reads above, and half a path is more misleading
     * than a short one.
     */
    private function humanPath(int $folderId): string
    {
        $folder = $this->folders[$folderId] ?? null;

        if (null === $folder) {
            return '';
        }

        $names = [];

        foreach (FolderPath::ids($folder['path']) as $id) {
            if (isset($this->folders[$id])) {
                $names[] = $this->folders[$id]['name'];
            }
        }

        // A folder whose own path is empty — pre-migration data, in theory.
        // Its own name is still the truth about it.
        return $names === [] ? $folder['name'] : implode(' / ', $names);
    }
}
