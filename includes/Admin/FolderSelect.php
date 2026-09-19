<?php

declare(strict_types=1);

namespace FolderFolio\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use FolderFolio\Domain\AttachmentFolderRepository;
use FolderFolio\Domain\FolderService;

/**
 * The "All folders" select in the Media Library's filter bar — screens 03,
 * 06 and 11.
 *
 * ## Why this is printed by PHP rather than rendered by the rail app
 *
 * List mode's filter bar lives inside `#posts-filter`, which is a GET form
 * with a Filter button. A `<select name="folderfolio_folder">` printed into it
 * therefore filters the library with no JavaScript involved at all —
 * MediaLibraryFilter reads the same query var whether it arrived from a form
 * submit or from the rail. That is what the handoff means by "degrades to a
 * plain indented <select>", and it is not a hypothetical: the select is the
 * only folder control on the screen once scripts fail, since the rail is a
 * React app.
 *
 * It also means the control comes back from the server, already showing the
 * right folder, every time lib/list-refresh.ts swaps the bar — so the one
 * place the selected option could go stale is handled by not having to handle
 * it.
 *
 * Grid mode gets the React copy instead (apps/rail/FolderSelect.tsx): its
 * toolbar is built by Backbone after load, so there is nothing here to print
 * into.
 *
 * ## Where it lands
 *
 * `restrict_manage_posts` fires inside `WP_Media_List_Table::extra_tablenav()`
 * with `$which === 'bar'` — between the date filter and the Filter button,
 * inside `.wp-filter .actions`. That is exactly where screen 03 draws it:
 * after "All dates". Note that this is *not* inside `.tablenav.top`, which is
 * a different row holding the bulk actions; getting those two confused is how
 * the select ends up below the table.
 */
final class FolderSelect
{
    public function __construct(
        private readonly FolderService $folders = new FolderService(),
        private readonly AttachmentFolderRepository $assignments = new AttachmentFolderRepository()
    ) {
    }

    public function register(): void
    {
        add_action('restrict_manage_posts', [$this, 'render'], 10, 2);
    }

    /**
     * @param string $postType Post type of the list table being rendered.
     * @param string $which    Which tablenav: 'top', 'bottom' or — for the
     *                         media list table's filter bar — 'bar'.
     */
    public function render($postType, $which = ''): void
    {
        if ('attachment' !== (string) $postType || 'bar' !== (string) $which) {
            return;
        }

        if (!current_user_can('upload_files')) {
            return;
        }

        $current = MediaLibraryFilter::normalizeFolderId(
            $_GET[MediaLibraryFilter::QUERY_VAR] ?? null
        );

        $counts = $this->assignments->libraryCounts();
        // Default object type, and the site's own count setting rather than
        // a literal: the select and the rail have to agree, and they only do
        // if neither of them decides for itself.
        $rows = self::flatten($this->folders->tree());

        ?>
        <label class="screen-reader-text" for="folderfolio-folder-filter">
            <?php esc_html_e('Filter by folder', 'folderfolio'); ?>
        </label>
        <select
            name="<?php echo esc_attr(MediaLibraryFilter::QUERY_VAR); ?>"
            id="folderfolio-folder-filter"
            class="folderfolio folderfolio-folder-select<?php echo null === $current ? '' : ' is-active'; ?>"
        >
            <option value="" <?php selected(null === $current); ?>>
                <?php echo esc_html(self::label(__('All media', 'folderfolio'), 0, (int) $counts['all'])); ?>
            </option>
            <option value="0" <?php selected(0 === $current); ?>>
                <?php echo esc_html(self::label(__('Unassigned', 'folderfolio'), 0, (int) $counts['unassigned'])); ?>
            </option>
            <?php
            foreach ($rows as $row) :
                $selected = $row['id'] === $current;
                $indented = self::label((string) $row['name'], $row['depth'] + 1, (int) $row['total_count']);
                ?>
                <option
                    value="<?php echo (int) $row['id']; ?>"
                    data-folderfolio-indented="<?php echo esc_attr($indented); ?>"
                    <?php selected($selected); ?>
                >
                    <?php
                    /*
                     * The selected row is printed without its indent, because
                     * a shut <select> shows only this one option and nine
                     * non-breaking spaces in front of a name reads as a
                     * control that has lost its value. The indent says "inside
                     * that one", and that sentence needs the other rows on
                     * screen to mean anything.
                     *
                     * The indented label rides along in the data attribute so
                     * that FolderSelect.tsx can put it back the instant before
                     * the list opens — and so that it is put back from what
                     * was printed rather than from a reconstruction.
                     *
                     * With scripts off nothing puts it back, and the open list
                     * shows one un-indented row. That is the right way round:
                     * the control is readable, and it is the degraded path.
                     */
                    echo esc_html(
                        $selected
                            ? self::label((string) $row['name'], 0, (int) $row['total_count'])
                            : $indented
                    );
                    ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * The tree as a flat list carrying its own indent depth.
     *
     * Depth is counted here rather than read from the row's `depth` column,
     * because what this needs is the indent of the list being rendered, and
     * the two would differ the moment a filter ever hid a level.
     *
     * @param list<array<string, mixed>> $nodes
     * @return list<array{id: int, name: string, depth: int, total_count: int}>
     */
    private static function flatten(array $nodes, int $depth = 0): array
    {
        $out = [];

        foreach ($nodes as $node) {
            $out[] = [
                'id' => (int) $node['id'],
                'name' => (string) ($node['name'] ?? ''),
                'depth' => $depth,
                'total_count' => (int) ($node['total_count'] ?? $node['count'] ?? 0),
            ];

            $out = array_merge($out, self::flatten((array) ($node['children'] ?? []), $depth + 1));
        }

        return $out;
    }

    /**
     * One option's text: indent, name, then the count in brackets.
     *
     * Non-breaking spaces because an `<option>` collapses ordinary ones, and
     * an em quad before the figure because a native option cannot right-align
     * anything — which is the one thing screen 11 draws that no browser will
     * render.
     *
     * **The brackets are not decoration.** A gap alone leaves `Archive 29 0`,
     * and a reader has no way to tell the 29 in the name from the 0 that is the
     * count — the stress fixture is full of names ending in a number, and so
     * are real libraries (`2024`, `Q3 2025`, `Campaign 12`). Brackets say which
     * figure belongs to the folder and which to the library, and they are
     * **wp-admin's own convention**: `walker_category_dropdown` prints
     * `Name&nbsp;&nbsp;(12)` in every category select in this admin.
     *
     * Only the native select needs them. Everywhere else the count is its own
     * element — a badge on a rail row, a `__count` span in the picker — and a
     * box around a number needs no brackets to be read as one.
     *
     * Paths are indented rather than written `Brand / Logos / Primary`: an
     * option has a single label for both the open list and the closed control,
     * the note beside screen 11 asks for the indent, and the full path is
     * already on screen in the breadcrumb above.
     */
    private static function label(string $name, int $depth, int $count): string
    {
        return str_repeat("\u{00a0}\u{00a0}\u{00a0}", max(0, $depth))
            . $name
            . "\u{2003}(" . $count . ')';
    }
}
