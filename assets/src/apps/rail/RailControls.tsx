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
import {
    ArrowUpDownIcon,
    ChevronsDownUpIcon,
    ChevronsUpDownIcon,
    EllipsisIcon,
} from './icons';
import { AnchoredMenu } from './Menu';
import type { FolderNode } from './queries';
import { Search } from './Search';
import { SORT_LABELS, sortTree, useRail } from './store';
import { can, hasFolderMenu } from '../../lib/can';
import { useIsNarrow } from '../../lib/narrow';
import { t } from '../../core/api';

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
    const expandedIds = useRail((s) => s.expandedIds);
    const expandAll = useRail((s) => s.expandAll);
    const collapseAll = useRail((s) => s.collapseAll);
    const [sortOpen, setSortOpen] = useState(false);
    const [moreOpen, setMoreOpen] = useState(false);
    // The buttons the two menus are pinned to (AnchoredMenu).
    const [sortButton, setSortButton] = useState<HTMLButtonElement | null>(null);
    const [moreButton, setMoreButton] = useState<HTMLButtonElement | null>(null);

    // Only a real folder has a menu. All media and Unassigned are selections
    // but not folders, which is exactly the case a disabled state is for.
    //
    // Whether there is anything in it is `hasFolderMenu()`, the same question
    // the row's ⋮ asks. Since 23 Sep that is every rail user — anyone can
    // star — and each action inside is gated on its own ability.
    const actable = selected !== null;
    const canOpenMenu = hasFolderMenu();

    // Sorted here as well as in each renderer, and deliberately: Move up and
    // Move down step through the order on screen, and the two renderers each
    // do their own sorting from the same raw tree. Reaching into one of them
    // for its copy would tie this to whichever is mounted.
    const ordered = useMemo(() => sortTree(nodes, sort), [nodes, sort]);

    /*
     * Every folder that has children — the argument to expandAll.
     *
     * Walked from `nodes` rather than `ordered`, because which folders have
     * children does not depend on what order anything is in, and pinning it
     * to the raw tree keeps this out of the way of every sort change.
     */
    const parentIds = useMemo(() => {
        const out: number[] = [];

        (function walk(list: FolderNode[]) {
            for (const node of list) {
                if (node.children.length > 0) {
                    out.push(node.id);
                    walk(node.children);
                }
            }
        })(nodes);

        return out;
    }, [nodes]);

    /*
     * One button, and `anything` rather than `everything` decides which way
     * it points.
     *
     * After expanding, one press puts it back — which is the press that
     * matters, because expanding 1,053 folders is the thing a person most
     * wants to undo. Two manually opened folders also collapse, and that is
     * the same intent: "put the tree away".
     */
    const anyExpanded = expandedIds.size > 0;

    return (
        <div className="folderfolio-rail__controls">
            <Search />

            {/*
              Wide only. `Levels` is a drill-down with no expanded-node state
              — there is nothing below 782px for this to expand — so it is
              absent there rather than disabled, the same way the ⋮ is.

              No permission and never disabled, like Sort: what is folded up
              is a view, not a property of anybody's folders.
            */}
            {!narrow && parentIds.length > 0 ? (
                <button
                    type="button"
                    className="folderfolio-rail__control"
                    title={anyExpanded ? t('collapseAll', 'Collapse all') : t('expandAll', 'Expand all')}
                    aria-label={
                        anyExpanded ? t('collapseAll', 'Collapse all') : t('expandAll', 'Expand all')
                    }
                    onClick={() => (anyExpanded ? collapseAll() : expandAll(parentIds))}
                >
                    {anyExpanded ? <ChevronsDownUpIcon size={14} /> : <ChevronsUpDownIcon size={14} />}
                </button>
            ) : null}

            {/* Sort is about the view, not the selection, so it is never
                disabled and carries no permission. */}
            <div className="folderfolio-rail__control-wrap">
                <button
                    ref={setSortButton}
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
                    <AnchoredMenu
                        anchor={sortButton}
                        className="folderfolio-menu--end"
                        label={t('sort', 'Sort')}
                        onClose={() => setSortOpen(false)}
                    >
                        {SORT_LABELS.map((option) => (
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
                    </AnchoredMenu>
                ) : null}
            </div>

            {narrow ? (
                <div className="folderfolio-rail__control-wrap">
                    <button
                        ref={setMoreButton}
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
                            anchor={moreButton}
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
