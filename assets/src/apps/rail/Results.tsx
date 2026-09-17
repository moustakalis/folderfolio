/**
 * Search results — a flat list, name over path.
 *
 * See Search.tsx for why the tree is replaced rather than filtered.
 */

import { useMemo } from 'react';

import { FolderIcon } from './icons';
import type { FolderNode } from './queries';
import { useRail } from './store';
import { t } from '../../core/api';

interface Hit {
    node: FolderNode;
    /** Ancestor names, root first — what the second line shows. */
    trail: string[];
}

function search(nodes: FolderNode[], needle: string, trail: string[] = [], out: Hit[] = []) {
    for (const node of nodes) {
        if (node.name.toLowerCase().includes(needle)) {
            out.push({ node, trail });
        }

        // Always recurse, match or not: a matching folder inside a
        // non-matching parent is exactly the case the tree filter got wrong.
        search(node.children, needle, [...trail, node.name], out);
    }

    return out;
}

export function Results({ nodes }: { nodes: FolderNode[] }) {
    const query = useRail((s) => s.query);
    const selectedId = useRail((s) => s.selectedId);
    const select = useRail((s) => s.select);

    const needle = query.trim().toLowerCase();
    const hits = useMemo(() => (needle ? search(nodes, needle) : []), [nodes, needle]);

    if (hits.length === 0) {
        return (
            <p className="folderfolio-rail__empty">
                {t('noMatch', 'No folder matches “%s”.', query.trim())}
            </p>
        );
    }

    return (
        <ul className="folderfolio-tree folderfolio-results" role="listbox" aria-label={t('folders', 'Folders')}>
            {hits.map(({ node, trail }) => (
                <li key={node.id}>
                    <button
                        type="button"
                        className="folderfolio-row folderfolio-results__row"
                        role="option"
                        aria-selected={selectedId === node.id}
                        onClick={() => select(node.id)}
                        style={node.color ? ({ '--ff-folder': node.color } as React.CSSProperties) : undefined}
                    >
                        <span className="folderfolio-row__icon">
                            <FolderIcon />
                        </span>

                        <span className="folderfolio-results__text">
                            <span className="folderfolio-row__name">{node.name}</span>
                            <span className="folderfolio-results__path">
                                {trail.length === 0 ? t('atTopLevel', 'Top level') : trail.join(' / ')}
                            </span>
                        </span>

                        <span className="folderfolio-row__count">{node.total_count}</span>
                    </button>
                </li>
            ))}
        </ul>
    );
}
