/**
 * The Starred group — tier 2 item 10, answer 3 on board 3ZU8VGkJemznTvKp8tNnvY.
 *
 * A star is each person's own, and a mark alone does little on a tree of a
 * thousand folders: a star inside a collapsed parent is visible to nobody. So
 * the stars are also a short list of shortcuts between Unassigned and the
 * search — shown only once this person has one, so nobody else's rail
 * changes. A shortcut selects its folder and reveals it in the tree, the way
 * a deep link does. A nested folder names its parent in grey, so two folders
 * called Logos can be told apart.
 *
 * Stars on folders that no longer exist are skipped here rather than pruned
 * from the preference: the tree may simply not have loaded yet, and a
 * shortcut is cheaper to hide than to lose.
 */

import { useMemo } from 'react';

import { StarIcon } from './icons';
import type { FolderNode } from './queries';
import { useRail } from './store';
import { t } from '../../core/api';

interface Found {
    node: FolderNode;
    parentName: string | null;
    ancestorIds: number[];
}

/** id → the node, its parent's name and its ancestors, in one walk. */
function index(nodes: readonly FolderNode[]): Map<number, Found> {
    const out = new Map<number, Found>();

    const walk = (level: readonly FolderNode[], parent: FolderNode | null, ancestors: number[]) => {
        for (const node of level) {
            out.set(node.id, { node, parentName: parent?.name ?? null, ancestorIds: ancestors });
            walk(node.children, node, [...ancestors, node.id]);
        }
    };

    walk(nodes, null, []);

    return out;
}

export function Starred({ nodes }: { nodes: readonly FolderNode[] }) {
    const stars = useRail((s) => s.stars);
    const selectedId = useRail((s) => s.selectedId);
    const select = useRail((s) => s.select);
    const reveal = useRail((s) => s.reveal);

    const found = useMemo(() => {
        if (stars.length === 0) {
            return [];
        }

        const byId = index(nodes);

        return stars.map((id) => byId.get(id)).filter((entry): entry is Found => entry !== undefined);
    }, [nodes, stars]);

    if (found.length === 0) {
        return null;
    }

    return (
        <div className="folderfolio-rail__starred" role="group" aria-label={t('starredGroup', 'Starred')}>
            <p className="folderfolio-rail__starred-label" aria-hidden="true">
                {t('starredGroup', 'Starred')}
            </p>

            <div className="folderfolio-rail__starred-list">
                {found.map(({ node, parentName, ancestorIds }) => (
                    <button
                        key={node.id}
                        type="button"
                        className="folderfolio-row folderfolio-rail__fixed-row folderfolio-rail__starred-row"
                        aria-selected={selectedId === node.id}
                        aria-label={
                            parentName === null
                                ? `${node.name}, ${node.total_count}`
                                : t('starredIn', '%1$s in %2$s, %3$s', node.name, parentName, String(node.total_count))
                        }
                        onClick={() => {
                            reveal(ancestorIds);
                            select(node.id);
                        }}
                    >
                        <span className="folderfolio-row__icon folderfolio-row__star">
                            <StarIcon filled />
                        </span>
                        <span className="folderfolio-row__name">
                            {node.name}
                            {parentName === null ? null : (
                                <span className="folderfolio-rail__starred-parent"> · {parentName}</span>
                            )}
                        </span>
                        <span className="folderfolio-row__count">{node.total_count}</span>
                    </button>
                ))}
            </div>
        </div>
    );
}
