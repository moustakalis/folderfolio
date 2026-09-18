/**
 * The folder column inside the media frame — screen 10, the cramped case.
 *
 * ## The same components, two numbers
 *
 * Nothing here re-implements a row, a tree, a search field or a breadcrumb.
 * They are the rail's, rendered inside a container that declares the frame's
 * geometry — 32px rows, 20px indent, a 16px switcher, the count as plain text
 * instead of a tag. That is the claim the handoff makes about the row
 * component ("parameterised rather than hard-coded"), and this screen is where
 * it is either true or it is not.
 *
 * What genuinely differs is the header: at 240px the rail's four labelled
 * toolbar buttons do not fit, so the design collapses them into one 26px
 * overflow button. That is a different control, not a smaller one, so it is
 * written here.
 */

import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

import { FrameHeader } from './FrameHeader';
import { Breadcrumb, type Crumb } from '../rail/Breadcrumb';
import { FixedRows } from '../rail/FixedRows';
import { Results } from '../rail/Results';
import { Search } from '../rail/Search';
import { Toast, UNDO_WINDOW } from '../rail/Toast';
import { Tree } from '../rail/Tree';
import {
    useCreateFolder,
    useDeleteFolder,
    useRenameFolder,
    useTree,
    type FolderNode,
} from '../rail/queries';
import { useRail } from '../rail/store';
import { filterFrame, frameColumnPlace, frameFooterPlace } from '../../lib/media-frame';
import { useToolbarSlot } from '../../lib/toolbar-slot';
import { t } from '../../core/api';

/**
 * The part that watches for a frame, and nothing else.
 *
 * Split from the body on purpose: everything below mounts only once there is a
 * column to mount into, so an admin screen where nobody opens a picker does no
 * work at all — no folder request, no store subscriptions, no DOM. The tree is
 * fetched the moment a frame appears, which is soon enough; the alternative is
 * a request on every post-edit screen for a panel most of them never show.
 */
export function Frame() {
    const column = useToolbarSlot(frameColumnPlace, 'frame');

    if (!column) {
        return null;
    }

    return <FrameBody column={column} />;
}

function FrameBody({ column }: { column: HTMLElement }) {
    const { data, isPending, isError, refetch } = useTree();
    const nodes = data ?? [];

    const footer = useToolbarSlot(frameFooterPlace, 'frame-footer');

    const query = useRail((s) => s.query);
    const selectedId = useRail((s) => s.selectedId);
    const editing = useRail((s) => s.editing);
    const edit = useRail((s) => s.edit);
    const select = useRail((s) => s.select);
    const reveal = useRail((s) => s.reveal);
    const pendingUndo = useRail((s) => s.pendingUndo);
    const setPendingUndo = useRail((s) => s.setPendingUndo);

    const create = useCreateFolder();
    const rename = useRenameFolder();
    const remove = useDeleteFolder();
    const deferred = useRef<{ commit: () => Promise<void>; restore: () => void } | null>(null);

    /**
     * Selection filters the frame, and nothing else.
     *
     * No URL is written: the address bar belongs to the post being edited, not
     * to a picker that is about to close. That is the one behavioural
     * difference from the rail, and it is why this app does not use
     * lib/filter.ts.
     *
     * The frame may not have built its browser view yet when the column first
     * renders, so a failed attempt is retried once the column is in place
     * rather than dropped.
     */
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        if (!filterFrame(selectedId) && attempt < 10) {
            const timer = setTimeout(() => setAttempt((n) => n + 1), 100);

            return () => clearTimeout(timer);
        }
    }, [selectedId, attempt]);

    /** A folder chosen from anywhere is opened down to, as in the rail. */
    useEffect(() => {
        if (selectedId === null || selectedId <= 0 || nodes.length === 0) {
            return;
        }

        const ancestors = ancestorsOf(nodes, selectedId);

        if (ancestors && ancestors.length > 0) {
            reveal(ancestors);
        }
    }, [nodes, selectedId]);

    const findNode = (list: FolderNode[], id: number): FolderNode | null => {
        for (const node of list) {
            if (node.id === id) {
                return node;
            }

            const deeper = findNode(node.children, id);

            if (deeper) {
                return deeper;
            }
        }

        return null;
    };

    const selectedNode = selectedId !== null && selectedId > 0 ? findNode(nodes, selectedId) : null;

    const saveEdit = () => {
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
    };

    const startDelete = (node: FolderNode) => {
        void deferred.current?.commit();
        deferred.current = remove.remove(node.id);

        setPendingUndo({
            folderId: node.id,
            name: node.name,
            fileCount: node.count,
            deadline: Date.now() + UNDO_WINDOW,
        });

        if (selectedId === node.id) {
            select(null);
        }
    };

    /**
     * A pending delete is not lost when the picker goes away.
     *
     * Closing a media modal does not remove it from the document — core hides
     * it and keeps the views — so this component is still mounted and its
     * five-second window keeps running in the toast, which is portaled to the
     * body and therefore still on screen. That is the normal path, and it is
     * the right one: the delete happens on time and Undo is still reachable.
     *
     * The two handlers here are for the exits that are *not* normal. The
     * unmount cleanup covers a frame that really is destroyed; `pagehide`
     * covers leaving the page, and covers the back/forward cache path that
     * `beforeunload` does not. Either way an unsent delete is sent rather than
     * dropped — and if the request does not make it, the folder is still there
     * next time, which is the safe direction for a failure to fall.
     */
    useEffect(() => {
        const flush = () => void deferred.current?.commit();

        window.addEventListener('pagehide', flush);

        return () => {
            window.removeEventListener('pagehide', flush);
            flush();
            deferred.current = null;
        };
    }, []);

    const trail = selectedId === null || selectedId <= 0 ? [] : trailTo(nodes, selectedId) ?? [];

    const crumbs: Crumb[] = [
        { id: null, label: t('allMedia', 'All media') },
        ...(selectedId === 0 ? [{ id: 0, label: t('unassigned', 'Unassigned') }] : []),
        ...trail.map((node) => ({ id: node.id as number | null, label: node.name })),
    ];

    return (
        <>
            {/*
              Screen 10's footer line, and only while it is true.
              A folder is selected, so an upload made from this frame will be
              filed into it — core/upload-target.ts puts the id on the request
              and Support\UploadTarget reads it back. With All media or
              Unassigned showing there is no folder to go to, and a sentence
              that is false half the time teaches people to stop reading it.
            */}
            {footer && selectedNode
                ? createPortal(
                      <p className="folderfolio folderfolio-frame__uploads">
                          {t('uploadsGoToFolder', 'Uploads go to the selected folder.')}
                      </p>,
                      footer
                  )
                : null}

            {createPortal(
                <div className="folderfolio folderfolio-frame">
                    <FrameHeader
                        selected={selectedNode}
                        onDelete={() => selectedNode && startDelete(selectedNode)}
                    />
                    <FixedRows />
                    <Search />

                    {/*
                      The path, in the column rather than above the tiles.

                      Screen 10 draws it over the attachments, and that is not
                      where it can go: core lays the attachments browser out
                      with absolutely positioned children — the toolbar and the
                      scrolling tile area both — so a sibling in normal flow
                      renders *behind* the toolbar, and making room for it means
                      overriding an offset core recomputes on every resize. One
                      row of text is not worth owning that.

                      Under the search it is still on screen, still says where
                      you are, and it is beside the tree it describes. The
                      overflow rule earns its keep at this width: 220px
                      collapses a four-deep path into root, ellipsis, current.
                    */}
                    <div className="folderfolio-frame__crumbs">
                        <Breadcrumb crumbs={crumbs} />
                    </div>

                    <div className="folderfolio-frame__body">
                        {isError ? (
                            <div className="folderfolio-rail__error" role="alert">
                                <p>{t('treeFailed', 'Could not load your folders.')}</p>
                                <button
                                    type="button"
                                    className="folderfolio-rail__ghost"
                                    onClick={() => void refetch()}
                                >
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
                </div>,
                column
            )}

            {pendingUndo
                ? createPortal(
                      <Toast
                          onUndo={() => {
                              deferred.current?.restore();
                              deferred.current = null;
                              setPendingUndo(null);
                          }}
                          onExpire={() => {
                              void deferred.current?.commit();
                              deferred.current = null;
                              setPendingUndo(null);
                          }}
                      />,
                      document.body
                  )
                : null}
        </>
    );
}

/** Root-first ancestor ids of a folder; null when it is not in this tree. */
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
