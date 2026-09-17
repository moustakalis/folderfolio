/**
 * What FolderFolio adds to the library column itself: the breadcrumb, the rule
 * under it, and the current folder's children.
 *
 * Rendered through a portal from inside the rail's React tree rather than as a
 * second root. Two roots would mean two QueryClients — so two copies of the
 * folder tree, fetched twice and free to disagree — and a Zustand store that
 * only happens to be shared because it is a module global. A portal makes the
 * sharing structural: this is the same tree, drawn somewhere else in the DOM.
 */

import { Breadcrumb, type Crumb } from './Breadcrumb';
import { Cards } from './Cards';
import type { FolderNode } from './queries';
import { useRail } from './store';
import { hasListTable } from '../../lib/list-refresh';
import { t } from '../../core/api';

export function Content({ nodes }: { nodes: FolderNode[] }) {
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

    return (
        <>
            <Breadcrumb crumbs={crumbs} />
            <div className="folderfolio-content__rule" />
            <Cards children={children} list={hasListTable()} />
        </>
    );
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
