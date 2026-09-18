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

import { Breadcrumb, type Crumb } from './Breadcrumb';
import { Cards } from './Cards';
import type { FolderNode } from './queries';
import { useRail } from './store';
import { hasListTable } from '../../lib/list-refresh';
import { t, tn } from '../../core/api';

/**
 * @param cards Whether to draw the folders here too, which is the fallback for
 *              a library whose filter row this build does not recognise —
 *              better above the toolbar than nowhere.
 */
export function Content({ nodes, cards }: { nodes: FolderNode[]; cards: boolean }) {
    const { crumbs, children, label } = useFolderContent(nodes);

    return (
        <>
            <Breadcrumb crumbs={crumbs} />
            <div className="folderfolio-content__rule" />
            {cards ? <Cards children={children} list={hasListTable()} label={label} /> : null}
        </>
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

    const trail = selectedId === null || selectedId <= 0 ? [] : trailTo(nodes, selectedId) ?? [];

    const crumbs: Crumb[] = [
        { id: null, label: t('allMedia', 'All media') },
        ...(selectedId === 0 ? [{ id: 0, label: t('unassigned', 'Unassigned') }] : []),
        ...trail.map((node) => ({ id: node.id as number | null, label: node.name })),
    ];

    /**
     * Unassigned has no children by definition — it is the absence of a
     * folder, not a place in the tree. Showing the root folders under it would
     * say the opposite.
     */
    const children =
        selectedId === null
            ? nodes
            : selectedId === 0
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
     * Two of the board's three cases are deliberately missing. It also draws
     * "No folders in Brand" and "Files in no folder — nothing to drill into",
     * which mean the line stands alone over empty space; Cards renders nothing
     * at all in that case, which is the answer DESIGN-TO-CODE.md's "Still
     * open" list was waiting for. So this is only ever read with at least one
     * card under it.
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
        : tn(
              'topLevelFolder',
              'topLevelFolders',
              children.length,
              '%s top-level folder',
              '%s top-level folders',
              children.length
          );

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
