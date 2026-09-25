/**
 * The folder sheet on a phone: one level at a time.
 *
 * ## The measurement that asked for this
 *
 * Against 1,053 folders at a 528px viewport, the open sheet is 538px tall, of
 * which fixed chrome — header, actions, the two fixed rows, search, footer —
 * takes 325. That leaves **190px of tree**. Fully expanded the tree is
 * 50,976px. Nought point three seven percent of the content is on screen at
 * once, and no cap fixes a ratio like that: raising it from 60vh to 75dvh
 * moved the window from 82px to 190 and the ratio barely noticed.
 *
 * Drill-down changes what is *in* the window rather than how big it is. A
 * level here averages **3.7 rows** and 203 of the 283 possible screens fit
 * whole — measured on the same fixture. It also retires the case for
 * virtualising the tree, which was the most expensive item on the list: there
 * is nothing to virtualise in a list of four.
 *
 * ## What it does not fix, and what does
 *
 * The screen you land on. The fixture's top level has 47 roots — 2,068px
 * against that same 190px window — and a realistic library with fifteen is
 * still 660px. So the level view is not enough on its own, and the thing that
 * finishes it is the search field above it, focused when the sheet opens. See
 * Search.tsx.
 *
 * ## Why this is a list and not a tree
 *
 * `role="tree"` brings a contract with it — a roving tabindex, arrow keys that
 * cross levels, expand and collapse on Left and Right — and that contract has
 * a CI harness precisely because it fails silently. None of it applies to a
 * screen showing one parent's children: there are no levels to cross, nothing
 * to expand, and every row is an ordinary tab stop. So this is a plain list
 * with a back control, which is one behaviour fewer to keep alive at narrow
 * width rather than one more.
 *
 * The desktop tree is untouched. Rail.tsx renders one or the other on
 * `useIsNarrow()`, never both.
 *
 * ## Tapping a row does two things, and that is the point
 *
 * It selects the folder — which is what filters the library behind the sheet —
 * and, if the folder has children, it walks into it. That is one gesture
 * meaning "open this folder": the library shows its files and the sheet shows
 * what else is inside it. A leaf selects and stays put, because there is
 * nowhere to walk to.
 *
 * Going *back* does not select. The header is where you are, not what you are
 * filtered to, and re-filtering the library on every back tap would make
 * retracing your steps a series of queries. Tapping the header's title is how
 * you say "and show me this one's files".
 */

import { useEffect, useMemo, useRef } from 'react';

import { ChevronRightIcon, FolderIcon, FolderOpenIcon } from './icons';
import { CreateRow, GhostRows, NameInput } from './Row';
import type { FolderNode } from './queries';
import { sortTree, useRail } from './store';
import { t, tn } from '../../core/api';
import { swatchStyle } from '../../lib/swatches';
import { EmptyTree } from './EmptyTree';
import { RowMarks, useMarkWords } from './RowMarks';

export interface LevelsProps {
    nodes: FolderNode[];
    loading: boolean;
}

/** The folder a level is standing in, and the trail back to the top. */
interface Standing {
    /** `null` at the top level. */
    node: FolderNode | null;
    /** Ancestors, root first, not including `node`. */
    trail: FolderNode[];
}

/** Find a folder and the path taken to reach it. */
function locate(
    nodes: FolderNode[],
    id: number,
    trail: FolderNode[] = []
): Standing | null {
    for (const node of nodes) {
        if (node.id === id) {
            return { node, trail };
        }

        const deeper = locate(node.children, id, [...trail, node]);

        if (deeper) {
            return deeper;
        }
    }

    return null;
}

export function Levels({ nodes, loading }: LevelsProps) {
    const levelId = useRail((s) => s.levelId);
    const openLevel = useRail((s) => s.openLevel);
    const selectedId = useRail((s) => s.selectedId);
    const select = useRail((s) => s.select);
    const sort = useRail((s) => s.sort);
    const editing = useRail((s) => s.editing);

    const ordered = useMemo(() => sortTree(nodes, sort), [nodes, sort]);

    /*
     * Where the sheet is standing, resolved against the tree it actually has.
     *
     * `levelId` can name a folder that is no longer there — deleted in this
     * tab, or gone from a refetch — and the answer then is the top level
     * rather than an empty screen with a back button to nowhere.
     */
    const standing = useMemo<Standing>(() => {
        if (levelId === null) {
            return { node: null, trail: [] };
        }

        return locate(ordered, levelId) ?? { node: null, trail: [] };
    }, [ordered, levelId]);

    const rows = standing.node === null ? ordered : standing.node.children;

    /**
     * A folder chosen anywhere else brings the sheet with it.
     *
     * The picker, the breadcrumb, a path in the Folders column, a deep link on
     * a cold load — all of them go through `select` and none of them knows
     * this view exists. Without this, choosing a folder from the toolbar left
     * the sheet showing whatever level it happened to be on, which is the
     * narrow-width version of the bug `reveal` fixes for the desktop tree.
     *
     * The folder itself when it has children — you asked for it, so its
     * contents are what to show — and otherwise its parent, so that the row
     * you chose is the one on screen rather than an empty level.
     */
    const followed = useRef<number | null | undefined>(undefined);

    useEffect(() => {
        if (selectedId === null || selectedId <= 0 || ordered.length === 0) {
            return;
        }

        /*
         * Only on a *change* of selection, never on a change of the tree.
         *
         * The effect has to depend on `ordered` — on a cold deep link the
         * selection arrives before the folders do, so it cannot be resolved
         * until they have — but `ordered` is a new array on every refetch, and
         * TanStack refetches on window focus. Without this guard, walking back
         * up a level and then switching tabs and back again threw the sheet
         * into the selected folder again, undoing the step the person had just
         * taken. The ref is marked only once the folder has actually been
         * found, so the cold-link case still fires on the pass where the tree
         * arrives.
         */
        if (followed.current === selectedId) {
            return;
        }

        const found = locate(ordered, selectedId);

        if (!found?.node) {
            return;
        }

        followed.current = selectedId;

        openLevel(
            found.node.children.length > 0
                ? found.node.id
                : (found.trail[found.trail.length - 1]?.id ?? null)
        );
    }, [ordered, selectedId]);

    if (loading) {
        return (
            <ul className="folderfolio-tree folderfolio-levels">
                <GhostRows />
            </ul>
        );
    }

    return (
        <div className="folderfolio-levels">
            {standing.node ? (
                <LevelHeader standing={standing} onUp={openLevel} onSelect={select} />
            ) : null}

            <ul className="folderfolio-tree folderfolio-levels__list">
                {/*
                  A new folder is created *into* the level being looked at, so
                  its input belongs at the top of that level rather than
                  wherever the tree would have put it.
                */}
                {editing?.mode === 'create' ? <CreateRow depth={0} buttons /> : null}

                {rows.map((node) => (
                    <LevelRow
                        key={node.id}
                        node={node}
                        renaming={
                            editing?.mode === 'rename' && editing.folderId === node.id
                        }
                        selected={selectedId === node.id}
                        onOpen={() => {
                            select(node.id);

                            if (node.children.length > 0) {
                                openLevel(node.id);
                            }
                        }}
                    />
                ))}

                {rows.length === 0 && editing === null ? (
                    /*
                     * Two empty sheets, and only one of them is about this
                     * plugin being new.
                     *
                     * At the root, this is the same question the wide rail's
                     * tree asks — is the library unfiled, or filed somewhere
                     * else — so it gets the same answer, from the same
                     * component. It did not, until it was looked at: below
                     * 782px the rail renders this sheet rather than the tree,
                     * so fixing `Tree.tsx` alone left the untrue sentence
                     * shipping on every phone.
                     *
                     * Inside a folder, "nothing inside this folder" is true
                     * whatever any other plugin holds, and stays a line.
                     */
                    standing.node === null ? (
                        <li className="folderfolio-levels__emptyRoot">
                            <EmptyTree />
                        </li>
                    ) : (
                        <li className="folderfolio-levels__empty">
                            {t('noSubfolders', 'Nothing inside this folder')}
                        </li>
                    )
                ) : null}
            </ul>
        </div>
    );
}

/**
 * Where you are, and the way back out.
 *
 * Two controls in one bar, because they are two different sentences. The
 * chevron and the parent's name go **up**; the title selects the folder you
 * are standing in. Both are full-height so that neither is a small target
 * inside a large one — the thing that made the old collapse chevron hard to
 * hit.
 */
function LevelHeader({
    standing,
    onUp,
    onSelect,
}: {
    standing: Standing;
    onUp: (id: number | null) => void;
    onSelect: (id: number) => void;
}) {
    // Subscribed rather than read once: the folder can be selected from the
    // toolbar picker while this header is on screen, and `aria-current` is the
    // only thing that says so.
    const selectedId = useRail((s) => s.selectedId);
    const renaming = useRail(
        (s) => s.editing?.mode === 'rename' && s.editing.folderId === standing.node?.id
    );

    const node = standing.node;

    if (!node) {
        return null;
    }

    const parent = standing.trail[standing.trail.length - 1] ?? null;

    const here = selectedId === node.id;

    return (
        /*
         * The current marker belongs to the whole bar, not to the title.
         *
         * Drawn on the title button it started three-fifths of the way along
         * the bar, between the way out and the folder name, where it read as a
         * divider between two controls rather than as "this is what the
         * library is showing". On the bar's own left edge it lines up with the
         * same marker on the rows below it, which is where the eye is already
         * looking for it.
         */
        <div className={`folderfolio-levels__head${here ? ' is-current' : ''}`}>
            <button
                type="button"
                className="folderfolio-levels__up"
                onClick={() => onUp(parent?.id ?? null)}
                /* The destination, not "Back": on a screen with no history
                   stack the useful word is where this goes. */
                aria-label={t(
                    'upToFolder',
                    'Up to %s',
                    parent?.name ?? t('atTopLevel', 'Top level')
                )}
            >
                <span className="folderfolio-levels__up-icon" aria-hidden="true">
                    <ChevronRightIcon size={16} />
                </span>
                <span className="folderfolio-levels__up-label">
                    {parent?.name ?? t('atTopLevel', 'Top level')}
                </span>
            </button>

            {/*
              Renaming the folder the sheet is standing in. Tapping a folder
              with children walks into it, so the folder being renamed is this
              title rather than a row below — and the field has to be here, or
              Rename opened nothing anyone could see (review H2).
            */}
            {renaming ? (
                <div className="folderfolio-levels__here is-renaming" style={swatchStyle(node.color)}>
                    <span className="folderfolio-row__icon">
                        <FolderOpenIcon />
                    </span>
                    <NameInput label={t('renameFolder', 'Rename folder')} buttons />
                </div>
            ) : (
            <button
                type="button"
                className="folderfolio-levels__here"
                aria-current={here ? 'true' : undefined}
                onClick={() => onSelect(node.id)}
                style={swatchStyle(node.color)}
            >
                <span className="folderfolio-row__icon">
                    <FolderOpenIcon />
                </span>
                <span className="folderfolio-row__name">{node.name}</span>
                <RowMarks id={node.id} pinned={node.pinned} lockedBy={node.locked_by} gallery={node.kind === 'gallery'} />
                <span className="folderfolio-row__count">{node.total_count}</span>
            </button>
            )}
        </div>
    );
}

function LevelRow({
    node,
    renaming,
    selected,
    onOpen,
}: {
    node: FolderNode;
    renaming: boolean;
    selected: boolean;
    onOpen: () => void;
}) {
    const inside = node.children.length;
    const cut = useRail((s) => s.clipboard?.verb === 'cut' && s.clipboard.id === node.id);
    const marks = useMarkWords(node.id, node.pinned, node.locked_by, node.kind === 'gallery');

    if (renaming) {
        return (
            <li role="none">
                <div className="folderfolio-row is-renaming" style={swatchStyle(node.color)}>
                    <span className="folderfolio-row__icon">
                        <FolderIcon />
                    </span>
                    <NameInput label={t('renameFolder', 'Rename folder')} buttons />
                </div>
            </li>
        );
    }

    return (
        <li>
            <button
                type="button"
                className={`folderfolio-row folderfolio-levels__row${cut ? ' is-cut' : ''}`}
                aria-current={selected ? 'true' : undefined}
                /*
                 * The row says what is inside it, because the chevron beside
                 * it is aria-hidden and a count on its own does not say
                 * whether tapping this goes anywhere.
                 */
                aria-label={
                    (inside === 0
                        ? `${node.name}, ${node.total_count}`
                        : t(
                              'folderWithSubfolders',
                              '%1$s, %2$s, %3$s',
                              node.name,
                              tn('levelRowFiles', 'levelRowFilesMany', node.total_count, '%s file', '%s files', node.total_count),
                              tn('levelRowFolders', 'levelRowFoldersMany', inside, '%s folder inside', '%s folders inside', inside)
                          )) + marks
                }
                onClick={onOpen}
                style={swatchStyle(node.color)}
            >
                <span className="folderfolio-row__icon">
                    <FolderIcon />
                </span>
                <span className="folderfolio-row__name">{node.name}</span>
                <RowMarks id={node.id} pinned={node.pinned} lockedBy={node.locked_by} gallery={node.kind === 'gallery'} labelled />
                <span className="folderfolio-row__count">{node.total_count}</span>

                {/* An affordance, not a second target: the whole row walks in.
                    Two adjacent controls a few pixels apart, one of which
                    filters and one of which does not, is the ambiguity this
                    view exists to remove. */}
                <span className="folderfolio-levels__into" aria-hidden="true">
                    {inside > 0 ? <ChevronRightIcon size={16} /> : null}
                </span>
            </button>
        </li>
    );
}
