/**
 * The rail's control line — what is left after the toolbar was dissolved.
 *
 * ## Why there is no toolbar any more
 *
 * It held Rename, Delete, Sort and More, all four acting on the selection.
 * Those three folder actions now live in `FolderMenu`, opened from a ⋮ on the
 * selected row — Nick's rule: no action in two places at one width. That left
 * a toolbar containing one control, and a 308px row holding a single button
 * is not a toolbar, it is a button with 200px of nothing beside it.
 *
 * So the one survivor moved to the line it belongs on. Sort is not a folder
 * action: it needs no selection and no permission, it is the order the whole
 * tree is filed in. Search is the same kind of thing — always available, about
 * the list rather than about a folder — and the two together read as one line
 * about finding things.
 *
 * ## Why More is here and only below 782px
 *
 * Above 782px the ⋮ on the selected row opens the folder menu. Below it there
 * is no ⋮: `LevelRow` renders the entire row as a single button, so a second
 * control inside it would be nested interactive markup and would undo the
 * decision the drill-down exists to express. More is that width's door to the
 * same component — never both at once.
 *
 * ## Why this wraps Search rather than living inside it
 *
 * `Search` is also rendered by the media modal's frame, which has never had a
 * toolbar and should not grow one. Wrapping it here leaves that path exactly
 * as it was.
 */

import { useMemo, useState } from 'react';

import { FolderMenu } from './FolderMenu';
import { ArrowUpDownIcon, EllipsisIcon } from './icons';
import { Menu } from './Menu';
import type { FolderNode } from './queries';
import { Search } from './Search';
import { sortTree, useRail, type SortOrder } from './store';
import { can } from '../../lib/can';
import { useIsNarrow } from '../../lib/narrow';
import { t } from '../../core/api';

const SORTS: Array<{ value: SortOrder; label: string; fallback: string }> = [
    { value: 'name-asc', label: 'sortNameAsc', fallback: 'Name, A to Z' },
    { value: 'name-desc', label: 'sortNameDesc', fallback: 'Name, Z to A' },
    { value: 'newest', label: 'sortNewest', fallback: 'Newest first' },
    { value: 'oldest', label: 'sortOldest', fallback: 'Oldest first' },
    // Last, and after a rule in the menu: the four above are views the tree
    // is put into, this one is the tree's own arrangement being shown.
    { value: 'custom', label: 'sortCustom', fallback: 'Custom order' },
];

export function RailControls({
    selected,
    nodes,
    onDelete,
}: {
    selected: FolderNode | null;
    nodes: FolderNode[];
    onDelete: () => void;
}) {
    const narrow = useIsNarrow();
    const sort = useRail((s) => s.sort);
    const setSort = useRail((s) => s.setSort);
    const [sortOpen, setSortOpen] = useState(false);
    const [moreOpen, setMoreOpen] = useState(false);

    // Only a real folder has a menu. All media and Unassigned are selections
    // but not folders, which is exactly the case a disabled state is for.
    //
    // The ability is `rename` OR `delete`, the same pair the row's ⋮ asks:
    // they are separate columns in the roles matrix and a role can hold
    // either one alone, so a role with neither gets no menu rather than an
    // empty one.
    const actable = selected !== null;
    const canOpenMenu = can('rename') || can('delete');

    // Sorted here as well as in each renderer, and deliberately: Move up and
    // Move down step through the order on screen, and the two renderers each
    // do their own sorting from the same raw tree. Reaching into one of them
    // for its copy would tie this to whichever is mounted.
    const ordered = useMemo(() => sortTree(nodes, sort), [nodes, sort]);

    return (
        <div className="folderfolio-rail__controls">
            <Search />

            {/* Sort is about the view, not the selection, so it is never
                disabled and carries no permission. */}
            <div className="folderfolio-rail__control-wrap">
                <button
                    type="button"
                    className="folderfolio-rail__control"
                    aria-haspopup="menu"
                    aria-expanded={sortOpen}
                    title={t('sort', 'Sort')}
                    aria-label={t('sort', 'Sort')}
                    onClick={() => setSortOpen((open) => !open)}
                >
                    <ArrowUpDownIcon size={14} />
                </button>

                {sortOpen ? (
                    <Menu className="folderfolio-menu--end" onClose={() => setSortOpen(false)}>
                        {SORTS.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                role="menuitemradio"
                                aria-checked={sort === option.value}
                                className="folderfolio-menu__item"
                                onClick={() => {
                                    setSort(option.value);
                                    setSortOpen(false);
                                }}
                            >
                                <span className="folderfolio-menu__tick" aria-hidden="true">
                                    {sort === option.value ? '✓' : ''}
                                </span>
                                {t(option.label, option.fallback)}
                            </button>
                        ))}
                    </Menu>
                ) : null}
            </div>

            {narrow ? (
                <div className="folderfolio-rail__control-wrap">
                    <button
                        type="button"
                        className="folderfolio-rail__control"
                        disabled={!actable || !canOpenMenu}
                        aria-haspopup="menu"
                        aria-expanded={moreOpen}
                        title={t('folderActions', 'Folder actions')}
                        aria-label={t('folderActions', 'Folder actions')}
                        onClick={() => setMoreOpen((open) => !open)}
                    >
                        <EllipsisIcon size={14} />
                    </button>

                    {moreOpen && selected ? (
                        <FolderMenu
                            folder={selected}
                            ordered={ordered}
                            onDelete={onDelete}
                            onClose={() => setMoreOpen(false)}
                        />
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}
