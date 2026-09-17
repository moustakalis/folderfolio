/**
 * The rail's interior, in the order screen 03 stacks it.
 *
 * The shell around this — width, stickiness, collapse, the footer — is
 * server-rendered by Rail.php and owned by rail.ts. This component owns
 * everything between the top edge and the footer.
 */

import { useCallback, useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';

import { Content } from './Content';
import { FixedRows } from './FixedRows';
import { Header } from './Header';
import { Results } from './Results';
import { Search } from './Search';
import { Toast, UNDO_WINDOW } from './Toast';
import { Toolbar } from './Toolbar';
import { Tree } from './Tree';
import {
    useCreateFolder,
    useDeleteFolder,
    useRenameFolder,
    useTree,
    type FolderNode,
} from './queries';
import { useRail } from './store';
import { applyFolderFilter } from '../../lib/filter';
import { t } from '../../core/api';

export function Rail({ contentMount }: { contentMount: HTMLElement | null }) {
    const { data, isPending, isError, refetch } = useTree();
    const nodes = data ?? [];

    const query = useRail((s) => s.query);
    const selectedId = useRail((s) => s.selectedId);
    const reveal = useRail((s) => s.reveal);
    const editing = useRail((s) => s.editing);
    const edit = useRail((s) => s.edit);
    const select = useRail((s) => s.select);
    const pendingUndo = useRail((s) => s.pendingUndo);
    const setPendingUndo = useRail((s) => s.setPendingUndo);

    const create = useCreateFolder();
    const rename = useRenameFolder();
    const remove = useDeleteFolder();

    /**
     * The delete that has not been sent yet.
     *
     * Held in a ref rather than in the store because it is a pair of closures,
     * not state: nothing renders differently because of it, and putting
     * functions in a store makes it something other than a description of the
     * screen.
     */
    const deferred = useRef<{ commit: () => Promise<void>; restore: () => void } | null>(null);

    const findNode = useCallback(
        function find(list: FolderNode[], id: number): FolderNode | null {
            for (const node of list) {
                if (node.id === id) {
                    return node;
                }

                const deeper = find(node.children, id);

                if (deeper) {
                    return deeper;
                }
            }

            return null;
        },
        []
    );

    const selectedNode = selectedId !== null && selectedId > 0 ? findNode(nodes, selectedId) : null;

    const saveEdit = useCallback(() => {
        if (!editing) {
            return;
        }

        const name = editing.value.trim();

        if (name === '') {
            return;
        }

        if (editing.mode === 'rename' && editing.folderId !== null) {
            rename.mutate({ id: editing.folderId, name });
            edit(null);

            return;
        }

        create.mutate(
            { name, parentId: editing.parentId },
            {
                onSuccess: (folder) => {
                    select(folder.id);
                    edit(null);
                },
            }
        );
    }, [editing, rename, create, edit, select]);

    const startDelete = useCallback(
        (node: FolderNode) => {
            // A second delete while one is still pending commits the first.
            // Two toasts would be two undo windows for two different folders
            // in one corner, and only one of them could be shown.
            void deferred.current?.commit();

            deferred.current = remove.remove(node.id);

            setPendingUndo({
                folderId: node.id,
                name: node.name,
                fileCount: node.count,
                deadline: Date.now() + UNDO_WINDOW,
            });

            // Standing on the row that just vanished is not a place to be.
            if (selectedId === node.id) {
                select(null);
            }
        },
        [remove, setPendingUndo, selectedId, select]
    );

    const undoDelete = useCallback(() => {
        deferred.current?.restore();
        deferred.current = null;
        setPendingUndo(null);
    }, [setPendingUndo]);

    const commitDelete = useCallback(() => {
        void deferred.current?.commit();
        deferred.current = null;
        setPendingUndo(null);
    }, [setPendingUndo]);

    /**
     * Leaving the page inside the window still deletes.
     *
     * pagehide rather than beforeunload: it fires on the back/forward cache
     * path too, which beforeunload does not. If the request does not make it,
     * the folder is still there on the next load — the safe direction for a
     * failure to fall.
     */
    useEffect(() => {
        const flush = () => void deferred.current?.commit();

        window.addEventListener('pagehide', flush);

        return () => window.removeEventListener('pagehide', flush);
    }, []);

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
        <>
            {/*
              The breadcrumb and the drill-down cards live in the library
              column, not in the rail — but they are the same React tree, so
              they share the store and the query client rather than fetching
              the folders a second time.
            */}
            {contentMount && !isError ? createPortal(<Content nodes={nodes} />, contentMount) : null}

            <div className="folderfolio-rail__app">
                <Header />
                <Toolbar selected={selectedNode} onDelete={() => selectedNode && startDelete(selectedNode)} />
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
                        <Tree
                            nodes={nodes}
                            loading={isPending}
                            onSaveEdit={saveEdit}
                            onCancelEdit={() => edit(null)}
                            onDelete={startDelete}
                        />
                    )}
                </div>
            </div>

            {/*
              Bottom-left of the content area, per screen 05 — portaled next to
              the breadcrumb rather than rendered inside the rail, which is a
              300px column with its own overflow and stacking context.
            */}
            {contentMount && pendingUndo
                ? createPortal(<Toast onUndo={undoDelete} onExpire={commitDelete} />, document.body)
                : null}
        </>
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
