/**
 * Search results — a flat list, name over path.
 *
 * See Search.tsx for why the tree is replaced rather than filtered.
 */

import { useMemo } from 'react';

import { FolderIcon } from './icons';
import { searchTree, type FolderNode } from './queries';
import { useRail } from './store';
import { t } from '../../core/api';
import { swatchStyle } from '../../lib/swatches';

export function Results({ nodes }: { nodes: FolderNode[] }) {
    const query = useRail((s) => s.query);
    const selectedId = useRail((s) => s.selectedId);
    const select = useRail((s) => s.select);

    const needle = query.trim().toLowerCase();
    const hits = useMemo(() => (needle ? searchTree(nodes, needle) : []), [nodes, needle]);

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
                        style={swatchStyle(node.color)}
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
