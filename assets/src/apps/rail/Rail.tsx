/**
 * The rail's interior, in the order screen 03 stacks it.
 *
 * The shell around this — width, stickiness, collapse, the footer — is
 * server-rendered by Rail.php and owned by rail.ts. This component owns
 * everything between the top edge and the footer.
 */

import { useEffect, useRef } from 'react';

import { FixedRows } from './FixedRows';
import { Header } from './Header';
import { Results } from './Results';
import { Search } from './Search';
import { Tree } from './Tree';
import { useTree, type FolderNode } from './queries';
import { useRail } from './store';
import { applyFolderFilter } from '../../lib/filter';
import { t } from '../../core/api';

export function Rail() {
    const { data, isPending, isError, refetch } = useTree();
    const nodes = data ?? [];

    const query = useRail((s) => s.query);
    const selectedId = useRail((s) => s.selectedId);
    const reveal = useRail((s) => s.reveal);

    /**
     * Selection drives the library, and only when it changes.
     *
     * Not called from the click handler: selection can also come from a deep
     * link, a breadcrumb or a newly created folder, and every one of those has
     * to filter the library the same way. One effect on the value is the only
     * arrangement where that is true by construction.
     *
     * The first run is skipped — arriving at ?folderfolio_folder=12 means the
     * server has already filtered, and re-applying would re-query for nothing.
     */
    const applied = useRef(false);

    useEffect(() => {
        // Arriving at ?folderfolio_folder=12 means the server already
        // filtered; re-applying on mount would re-query for nothing.
        if (!applied.current) {
            applied.current = true;

            return;
        }

        applyFolderFilter(selectedId);

        /*
         * The same event v0.2.0's tree dispatched. media-library-integration
         * and upload-integration both listen for it — the filter bar above the
         * grid and the default folder for a drop. They are not being rewritten
         * in this step, and there is no reason for them to know the rail is a
         * React app now.
         */
        window.dispatchEvent(
            new CustomEvent('folderfolio:folder-selected', {
                detail: { folderId: selectedId },
                bubbles: true,
            })
        );
    }, [selectedId]);

    /**
     * Arriving cold at a deep link has to open the tree down to that folder
     * and not just highlight a row nobody can see.
     */
    useEffect(() => {
        if (selectedId === null || selectedId <= 0 || nodes.length === 0) {
            return;
        }

        const ancestors = ancestorsOf(nodes, selectedId);

        if (ancestors && ancestors.length > 0) {
            reveal(ancestors);
        }
        // Only when the tree first arrives: afterwards the user owns what is
        // open, and re-revealing would fight them.
    }, [nodes]);

    return (
        <div className="folderfolio-rail__app">
            <Header />
            <FixedRows />
            <Search />

            <div className="folderfolio-rail__body">
                {isError ? (
                    <div className="folderfolio-rail__error" role="alert">
                        <p>{t('treeFailed', 'Could not load your folders.')}</p>
                        <p className="folderfolio-rail__error-detail">
                            {t('treeFailedWhere', 'The request to /folderfolio/v1/folders did not succeed.')}
                        </p>
                        <button type="button" className="folderfolio-rail__ghost" onClick={() => void refetch()}>
                            {t('retry', 'Retry')}
                        </button>
                    </div>
                ) : query.trim() !== '' ? (
                    <Results nodes={nodes} />
                ) : (
                    <Tree nodes={nodes} loading={isPending} />
                )}
            </div>
        </div>
    );
}

/**
 * Ancestor ids of a folder, root first.
 *
 * `null` means the folder is not in this tree; an empty array means it is at
 * the root and has no ancestors. The two are different answers and the caller
 * treats them differently, which is why this does not collapse both to [].
 */
function ancestorsOf(nodes: FolderNode[], id: number, trail: number[] = []): number[] | null {
    for (const node of nodes) {
        if (node.id === id) {
            return trail;
        }

        const deeper = ancestorsOf(node.children, id, [...trail, node.id]);

        if (deeper !== null) {
            return deeper;
        }
    }

    return null;
}
