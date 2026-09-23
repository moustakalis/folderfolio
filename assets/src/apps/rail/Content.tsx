/**
 * What FolderFolio adds to the library column itself: the breadcrumb, the rule
 * under it, and the current folder's children.
 *
 * Rendered through a portal from inside the rail's React tree rather than as a
 * second root. Two roots would mean two QueryClients — so two copies of the
 * folder tree, fetched twice and free to disagree — and a Zustand store that
 * only happens to be shared because it is a module global. A portal makes the
 * sharing structural: this is the same tree, drawn somewhere else in the DOM.
 *
 * Two portals, not one. Screen 03 stacks the column crumbs → rule → core's
 * filter row → folders → files, and core's filter row is not ours to move: in
 * grid mode it belongs to a Backbone view, in list mode to a form printed by
 * PHP. So the crumbs mount above it and the cards mount below it, and each
 * piece goes where it belongs rather than the whole block sitting above the
 * toolbar because that is where there happened to be a seam.
 */

import { useEffect, useMemo, useState } from 'react';

import { Breadcrumb, ClearFilter, type Crumb } from './Breadcrumb';
import { Cards } from './Cards';
import { CloseIcon } from './icons';
import type { FolderNode } from './queries';
import { StartHere } from './StartHere';
import { sortTree, useRail } from './store';
import { arrivedFromStartupFolder, folderFromUrl } from '../../lib/filter';
import { hasListTable } from '../../lib/list-refresh';
import { t, tn } from '../../core/api';

/**
 * @param cards Whether to draw the folders here too, which is the fallback for
 *              a library whose filter row this build does not recognise —
 *              better above the toolbar than nowhere.
 */
export function Content({ nodes, cards }: { nodes: FolderNode[]; cards: boolean }) {
    const { crumbs, children, label } = useFolderContent(nodes);
    const selectedId = useRail((s) => s.selectedId);

    return (
        <>
            {/*
              The controls are passed in rather than drawn by the breadcrumb,
              so the media picker — which renders the same component in a
              220px column — gets the compact pair and not these. The toggle
              is this screen's alone: a picker opened from a post editor is
              not somewhere you arrive.
            */}
            <Breadcrumb
                crumbs={crumbs}
                controls={
                    <>
                        <ClearFilter />
                        {selectedId === null ? null : <StartHere folderId={selectedId} />}
                    </>
                }
            />
            <StartupNote />
            <div className="folderfolio-content__rule" />
            {cards ? <Cards children={children} list={hasListTable()} label={label} /> : null}
        </>
    );
}

/**
 * The sentence that makes the startup folder not FileBird.
 *
 * FileBird forces its remembered folder onto `upload.php` with no user action
 * and nothing on screen: **28 of 47 files disappear** on a stock library and
 * the only clue is that the grid looks short. The folder's name in the
 * breadcrumb does not answer that on its own, because a crumb reads identically
 * whether the person chose the folder or the site did — and the person who has
 * just arrived is exactly the one who cannot tell.
 *
 * ## Why it is here and not inside `Breadcrumb`
 *
 * `.folderfolio-crumbs` has `overflow: hidden`, and that is load-bearing: the
 * overflow rule works by asking whether the row is wider than its box, which
 * it cannot report if it wraps. A second line inside that box would be
 * clipped, and would have made the crumb row's height a lie.
 *
 * It also keeps the media modal out of it. `Frame.tsx` renders the same
 * `Breadcrumb`, and a modal opened from a post editor is not an arrival at the
 * library — there is no redirect behind it and nothing to explain.
 */
function StartupNote() {
    /*
     * Both read once, in an initialiser, because both are facts about the
     * arrival rather than about now — and read in an initialiser rather than
     * an effect so the sentence is in the first paint beside the crumb it
     * explains.
     */
    const [shown, setShown] = useState(arrivedFromStartupFolder);
    const [arrival] = useState(folderFromUrl);

    const selectedId = useRail((s) => s.selectedId);

    /*
     * Gone the moment the person goes anywhere else — and this is not
     * belt-and-braces, it is the bug the browser found.
     *
     * The marker is stripped from the URL by `urlForFolder()` on every
     * selection, but clearing the filter never reloads the page (that is the
     * whole design of `applyFolderFilter`), so this component is not
     * remounted and its initialiser is not re-run. The × worked, the library
     * came back, and the sentence stayed on screen explaining a folder the
     * person was no longer in.
     *
     * A latch rather than `selectedId !== arrival` computed inline, so
     * choosing the startup folder again by hand later does not bring back a
     * sentence about an arrival that is long over.
     */
    useEffect(() => {
        if (selectedId !== arrival) {
            setShown(false);
        }
    }, [selectedId, arrival]);

    if (!shown) {
        return null;
    }

    return (
        <p className="folderfolio-startup-note">
            <span>
                {t(
                    'startupFolderNote',
                    'The media library opens in this folder. Clear the filter in the path above to see everything.'
                )}
            </span>

            {/*
              Dismissing is not clearing. Somebody who meant to be in this
              folder should be able to stop being told why they are in it
              without also leaving it — and somebody who did not mean to be
              has the × on the crumb, which is what this line points at.
            */}
            <button
                type="button"
                className="folderfolio-startup-note__dismiss"
                title={t('dismiss', 'Dismiss')}
                aria-label={t('dismiss', 'Dismiss')}
                onClick={() => setShown(false)}
            >
                <CloseIcon size={12} />
            </button>
        </p>
    );
}

/** The same children, drawn under core's filter row where screen 03 puts them. */
export function ContentCards({ nodes }: { nodes: FolderNode[] }) {
    const { children, label } = useFolderContent(nodes);

    return <Cards children={children} list={hasListTable()} label={label} />;
}

function useFolderContent(nodes: FolderNode[]): {
    crumbs: Crumb[];
    children: FolderNode[];
    label: string;
} {
    const selectedId = useRail((s) => s.selectedId);
    const sort = useRail((s) => s.sort);

    // The rail's order, not the wire's: until tier 2 item 10 these cards came
    // out in the server's order whatever the rail showed beside them, and a
    // pinned folder has to lead here as it does there.
    const sorted = useMemo(() => sortTree(nodes, sort), [nodes, sort]);
    const trail = selectedId === null || selectedId <= 0 ? [] : trailTo(sorted, selectedId) ?? [];

    const crumbs: Crumb[] = [
        { id: null, label: t('allMedia', 'All media') },
        ...(selectedId === 0 ? [{ id: 0, label: t('unassigned', 'Unassigned') }] : []),
        ...trail.map((node) => ({ id: node.id as number | null, label: node.name })),
    ];

    /**
     * Children of the folder you are *in* — and nothing at all when you are not
     * in one.
     *
     * Two cases return empty, for the same reason in different words.
     *
     * **Unassigned** has no children by definition: it is the absence of a
     * folder, not a place in the tree. Showing the root folders under it would
     * say the opposite.
     *
     * **All media** used to draw every root folder here, and that was the block
     * saying the least for the most. Measured on the 1,053-folder fixture at
     * the root: **510px at 1440 × 900**, which put the first thumbnail at 851px
     * and the library below the fold; 406px at 1680, 354px at 1920. Inside a
     * folder the same block is **42px**. And the 47 cards it drew were the 47
     * rail rows already on screen beside them — the same names, the same
     * counts, and `Row.tsx` makes those rows drop targets too, so the cards
     * were not even the only place to drop onto. Nick's words: useless unless a
     * specific folder is selected.
     *
     * What survives is the case the block is actually for: *what is inside the
     * folder I am in, besides files* — which the rail answers only if you have
     * expanded that branch.
     */
    const children =
        selectedId === null || selectedId === 0
            ? []
            : (trail[trail.length - 1]?.children ?? []);

    /**
     * The eyebrow over the cards, which the board computes rather than fixes.
     *
     * "Folders here" was a placeholder that said nothing the cards did not: it
     * is the same three words over three folders and over thirty, in the root
     * and five levels down. The board's version answers *how many* and
     * *where*, which is the one thing the row of cards cannot say about
     * itself.
     *
     * Three of the board's four cases are deliberately missing. It also draws
     * "No folders in Brand" and "Files in no folder — nothing to drill into",
     * which mean the line stands alone over empty space; Cards renders nothing
     * at all in that case. And its "%s top-level folders" went with the root
     * block above — an empty `children` never reaches an eyebrow, so the empty
     * string here is only ever a type, never a line on screen.
     */
    const here = trail[trail.length - 1];

    const label = here
        ? tn(
              'folderIn',
              'foldersIn',
              children.length,
              '%1$s folder in %2$s',
              '%1$s folders in %2$s',
              children.length,
              here.name
          )
        : '';

    return { crumbs, children, label };
}

/** The chain of nodes from the root down to `id`, inclusive. */
function trailTo(nodes: FolderNode[], id: number, trail: FolderNode[] = []): FolderNode[] | null {
    for (const node of nodes) {
        const here = [...trail, node];

        if (node.id === id) {
            return here;
        }

        const deeper = trailTo(node.children, id, here);

        if (deeper !== null) {
            return deeper;
        }
    }

    return null;
}
