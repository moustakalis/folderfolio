/**
 * The rail's interior, in the order screen 03 stacks it.
 *
 * The shell around this — width, stickiness, collapse, the footer — is
 * server-rendered by Rail.php and owned by rail.ts. This component owns
 * everything between the top edge and the footer.
 */

import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

import { Content, ContentCards } from './Content';
import { FixedRows } from './FixedRows';
import { Starred } from './Starred';
import { SmartGroup } from './SmartGroup';
import { Header } from './Header';
import { Levels } from './Levels';
import { LibraryToolbar } from './LibraryToolbar';
import { Results } from './Results';
import { Notice, Toast, UNDO_WINDOW } from './Toast';
import { watchDrags } from './drag';
import { watchTileDrops } from './file-order';
import { RailControls } from './RailControls';
import { Tree } from './Tree';
import {
    useCreateFolder,
    useDeleteFolder,
    useOrderFiles,
    useRenameFolder,
    useTree,
    type FolderNode,
} from './queries';
import { useRail } from './store';
import { can } from '../../lib/can';
import { useIsNarrow } from '../../lib/narrow';
import { applyFolderFilter, FOLDER_QUERY_VAR, keepServerOrder, showUploads, stampServerOrder, watchFolderLinks } from '../../lib/filter';
import { isMedia, t, tn } from '../../core/api';
import { watchListWidth } from '../../lib/list-width';

export function Rail({
    contentMount,
    findCardsMount,
}: {
    contentMount: HTMLElement | null;
    findCardsMount: () => HTMLElement | null;
}) {
    const cardsMount = useLateMount(findCardsMount);

    const { data, isPending, isError, refetch } = useTree();
    const nodes = data ?? [];

    const query = useRail((s) => s.query);
    const selectedId = useRail((s) => s.selectedId);
    const smartId = useRail((s) => s.smartId);
    const reveal = useRail((s) => s.reveal);
    const editing = useRail((s) => s.editing);
    const edit = useRail((s) => s.edit);
    const select = useRail((s) => s.select);
    const pendingUndo = useRail((s) => s.pendingUndo);
    const notice = useRail((s) => s.notice);
    const setPendingUndo = useRail((s) => s.setPendingUndo);

    /*
     * Below 782px the rail is a full-width band under the title rather than a
     * column beside the library, and the tree in it gets a 190px window. There
     * it drills one level at a time instead. One or the other, never both —
     * two navigation models rendered at once would be two sets of rows
     * claiming the same folders.
     */
    const narrow = useIsNarrow();

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
                unassigned: node.only_here ?? 0,
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
     * Files become draggable onto folders.
     *
     * The source folder is read at drag time through a ref-like getter rather
     * than captured, because the user changes folders between page load and
     * the drag — and the source is what decides whether the drop moves or
     * adds.
     */
    const selectedRef = useRef(selectedId);
    selectedRef.current = selectedId;

    useEffect(() => watchDrags(() => selectedRef.current), []);

    // Core's narrow list table when the rail leaves the table narrow
    // (lib/list-width.ts). Both list screens; nothing on the grid.
    useEffect(() => watchListWidth(), []);

    // Between two tiles of the folder being viewed, the same drag places
    // files instead of filing them (file-order.ts). Through a ref, so the
    // listener is attached once and still sends with the current mutation.
    const orderFiles = useOrderFiles();
    const orderRef = useRef(orderFiles.mutateAsync);
    orderRef.current = orderFiles.mutateAsync;

    useEffect(
        () =>
            watchTileDrops({
                folder: () => selectedRef.current,
                allowed: () => can('rename'),
                drop: (placement) => {
                    // A refusal is shown by the rail's MutationCache.
                    void orderRef.current(placement).catch(() => undefined);
                },
            }),
        []
    );

    /**
     * The Folders column's paths filter in place rather than navigating.
     *
     * Wired here rather than in the column's own bundle because `select` is
     * the store's, and going through it is what keeps the rail highlight, the
     * breadcrumb, the cards and the URL agreeing with the table.
     */
    useEffect(() => watchFolderLinks(select), [select]);

    /**
     * Add New from inside a folder files the new post there — tier 3 item 12
     * (Admin\PostFolders::fileNewPost). The link carries the folder being
     * viewed; All posts and Unassigned carry nothing. Media's Add New uploads,
     * and uploads already follow the folder (core/upload-target.ts).
     */
    useEffect(() => {
        if (isMedia()) {
            return;
        }

        document.querySelectorAll<HTMLAnchorElement>('#wpbody-content .wrap a.page-title-action').forEach((link) => {
            const url = new URL(link.href, window.location.href);

            if (!url.pathname.endsWith('post-new.php')) {
                return;
            }

            if (selectedId !== null && selectedId > 0) {
                url.searchParams.set(FOLDER_QUERY_VAR, String(selectedId));
            } else {
                url.searchParams.delete(FOLDER_QUERY_VAR);
            }

            link.href = url.toString();
        });
    }, [selectedId]);

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

    /**
     * The grid shows a folder in the server's order, not the date's.
     *
     * Core's collection re-sorts every page by its orderby in the browser, so
     * a folder's own file order and its Custom positions were re-sorted to
     * newest first on arrival — see keepServerOrder() in lib/filter.ts. Attached
     * once to the grid's collection; list mode has none and renders on the
     * server. media-grid.js builds the frame from a ready handler, which can be
     * after this mounts — the observer below is what finds it (trap 132).
     */
    useEffect(() => {
        // The stamp goes on the Query now, before the frame exists: a page the
        // server sends before it is in place carries no order to keep.
        stampServerOrder();

        const attach = (): boolean => {
            const collection = window.wp?.media?.frame?.content?.get?.()?.collection;

            if (!collection?.props) {
                return false;
            }

            keepServerOrder(collection as Parameters<typeof keepServerOrder>[0]);
            // And an upload made in a folder shows while it uploads —
            // showUploads() has why core does not.
            showUploads(collection as Parameters<typeof showUploads>[0]);

            return true;
        };

        if (attach()) {
            return;
        }

        /*
         * Not there yet: watch for it, rather than retry on a timer.
         *
         * The rail mounts before DOMContentLoaded (the bundle is deferred), and
         * media-grid.js builds the frame from a ready handler — measured 25 Sep
         * in the rig, this effect at ~208ms and the frame a few milliseconds
         * later. The old retry ran only if a frame already existed, so it
         * gave up, and the grid kept a folder's files in date order and never
         * showed an upload in its folder; it had been passing by the margin of
         * an effect's timing (trap 132). A timer that did find the frame found
         * it after the first page had been drawn, and the tiles then jumped.
         * A MutationObserver's callback runs as a microtask after the frame is
         * inserted — before the first page can come back from the server.
         *
         * Only where a grid is coming: `#wp-media-grid` is in the server's
         * markup on the grid, and list mode has neither it nor a frame.
         */
        const root = document.getElementById('wp-media-grid') ?? document.querySelector('.media-frame')?.parentElement;

        if (!root) {
            return;
        }

        const observer = new MutationObserver(() => {
            if (attach()) {
                observer.disconnect();
            }
        });

        observer.observe(root, { childList: true, subtree: true });

        const timer = setTimeout(() => observer.disconnect(), 10_000);

        return () => {
            observer.disconnect();
            clearTimeout(timer);
        };
    }, []);

    useEffect(() => {
        // Arriving at ?folderfolio_folder=12 means the server already
        // filtered; re-applying on mount would re-query for nothing.
        if (!applied.current) {
            applied.current = true;

            return;
        }

        applyFolderFilter(selectedId, smartId);

        /*
         * The same event v0.2.0's tree dispatched. media-library-integration
         * listens for it, and keeps the grid's own frame in step with the
         * selection. There is no reason for it to know the rail is a React
         * app now.
         *
         * upload-integration listened for it too, and is gone: it kept the
         * selected folder in a field nothing ever read.
         */
        window.dispatchEvent(
            new CustomEvent('folderfolio:folder-selected', {
                detail: { folderId: selectedId },
                bubbles: true,
            })
        );
    }, [selectedId, smartId]);

    /**
     * A selected folder is always visible in the tree.
     *
     * Two cases, one rule. Arriving cold at `?folderfolio_folder=12` has to
     * open the tree down to that folder rather than highlighting a row nobody
     * can see — and so does choosing a folder from somewhere that is not the
     * tree: the filter-row select, a path in the Folders column, a drill-down
     * card two levels in. All of those can name a folder inside a parent the
     * user has collapsed.
     *
     * This ran on `[nodes]` alone until step 8, which covered the cold link
     * and nothing else. Adding `selectedId` does not re-fight the user: the
     * effect only ever expands, only on a *change* of selection, and
     * collapsing a parent leaves the selection alone, so nothing here runs
     * again to undo it.
     */
    useEffect(() => {
        if (selectedId === null || selectedId <= 0 || nodes.length === 0) {
            return;
        }

        const ancestors = ancestorsOf(nodes, selectedId);

        if (ancestors && ancestors.length > 0) {
            reveal(ancestors);
        }
    }, [nodes, selectedId]);

    const scrollRef = useRef<HTMLDivElement>(null);

    useStickyControls(scrollRef);

    return (
        <>
            {/*
              The breadcrumb and the drill-down cards live in the library
              column, not in the rail — but they are the same React tree, so
              they share the store and the query client rather than fetching
              the folders a second time.
            */}
            {contentMount && !isError
                ? createPortal(
                      <Content nodes={nodes} cards={!narrow && cardsMount === null} />,
                      contentMount
                  )
                : null}

            {/*
              The folder cards are a desktop affordance.
              *
              * On a phone they are a second full-width list of the same
              * folders the sheet already shows, and they sit between the
              * toolbar and the files — 47 cards of them in the stress
              * fixture, which is most of a screen of folders before the first
              * thumbnail. The breadcrumb above stays: it says where you are in
              * one line, which is the part that does not duplicate the sheet.
            */}
            {cardsMount && !isError && !narrow
                ? createPortal(<ContentCards nodes={nodes} />, cardsMount)
                : null}

            {/*
              The folder select and the bulk Add-to-folder flyout, inside
              WordPress's own filter row. They portal themselves — see
              LibraryToolbar — so this renders nothing here.
            */}
            {isError ? null : <LibraryToolbar nodes={nodes} />}

            <div className="folderfolio-rail__app">
                <Header />
                <FixedRows />

                {/*
                  One scroller from here down — Nick, 25 Sep, option A on board
                  NF7bQktuksgBSi4rCfoLvf. Starred and Smart used to sit in the
                  fixed chrome above the search line, so every row in them came
                  out of the folder list: 31.8px of it at a 669px-tall phone,
                  none with five of each. Now they scroll away above the search
                  line, which sticks at the top of the scroller (and *Top level*
                  sticks beneath it), so the fixed chrome is the header, the two
                  fixed rows and the footer whatever anybody has starred.
                */}
                <div className="folderfolio-rail__scroll" ref={scrollRef}>
                    {/* This person's shortcuts — tier 2 item 10. Nothing without a star. */}
                    {isError ? null : <Starred nodes={nodes} />}
                    {/* Saved views — tier 3 item 13. Media only for now (13c). */}
                    {isError || !isMedia() ? null : <SmartGroup nodes={nodes} />}
                    <RailControls
                        selected={selectedNode}
                        nodes={nodes}
                        onDelete={() => selectedNode && startDelete(selectedNode)}
                    />

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
                        ) : narrow ? (
                            <Levels nodes={nodes} loading={isPending} />
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
            </div>

            {/*
              The footer's folder total. The footer is server-rendered by
              Rail.php so that Collapse works before this bundle loads and if
              it never does, which leaves an empty span for the one part of it
              that is data — filled from here, through the same query the tree
              reads, so the two can never disagree.
            */}
            {isError ? null : <FooterTotal nodes={nodes} pending={isPending} />}

            {/*
              Bottom-left of the content area, per screen 05 — portaled next to
              the breadcrumb rather than rendered inside the rail, which is a
              300px column with its own overflow and stacking context.
            */}
            {/*
              One corner, two sheets. The refusal sits above the undo toast
              when both are up — a delete that the server then refuses is the
              case where they are — so the stack is a grid in a fixed box and
              neither sheet positions itself.
            */}
            {(contentMount && pendingUndo) || notice
                ? createPortal(
                      <div className="folderfolio folderfolio-toasts">
                          <Notice />
                          {contentMount && pendingUndo ? (
                              <Toast onUndo={undoDelete} onExpire={commitDelete} />
                          ) : null}
                      </div>,
                      document.body
                  )
                : null}
        </>
    );
}

/**
 * "12 folders", bottom left — screen 03's footer.
 *
 * Every folder at every depth, not the top level and not what the search is
 * showing: it answers "how big is this tree", which is a property of the tree
 * and not of the view. It is also why it is not the count the badges show —
 * those count files.
 *
 * Read from the same cache the tree renders from, so a create, a delete and
 * an undo all move it without anything here subscribing to them. A delete is
 * optimistic — the row leaves the cache before the server is told — so the
 * total drops the moment the row does and comes back if the toast is undone,
 * which is the behaviour a number next to a disappearing row has to have.
 *
 * Nothing at all while the query is in flight. The tree shows three ghost
 * rows there, and "0 folders" underneath them would be a statement about the
 * site rather than about the request.
 */
function FooterTotal({ nodes, pending }: { nodes: FolderNode[]; pending: boolean }) {
    const slot = document.querySelector('[data-folderfolio-total]');

    if (!slot) {
        return null;
    }

    const total = countTree(nodes);

    const label = pending ? '' : tn('folderTotalOne', 'folderTotal', total, '%s folder', '%s folders', total);

    return createPortal(label, slot);
}

/** Every folder in the tree, at every depth. */
function countTree(nodes: FolderNode[]): number {
    return nodes.reduce((sum, node) => sum + 1 + countTree(node.children), 0);
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

/**
 * A mount point that may not exist yet, and may go away.
 *
 * The grid library's filter row is rendered by a Backbone view some time after
 * this bundle runs, and that view is re-rendered on a few of core's own
 * transitions — so resolving the anchor once at startup gives null on most
 * page loads, and holding the node it returned gives a detached div on the
 * rest. This resolves it whenever the document changes and stops as soon as it
 * has one, then watches for that node being torn out so it can be put back.
 *
 * The observer is the whole of #wpbody rather than a narrower root, for two
 * reasons: the element it is waiting for is the one that tells it where to
 * look, and #wpbody-content is itself replaceable — Premio's list-mode folder
 * click swaps the whole content column, and an observer attached to the old
 * one would be watching a detached node while the mount it is meant to
 * restore never comes back.
 */
function useLateMount(find: () => HTMLElement | null): HTMLElement | null {
    const [mount, setMount] = useState<HTMLElement | null>(find);

    useEffect(() => {
        if (mount?.isConnected) {
            // Already placed: watch only for it being removed.
            const root = document.getElementById('wpbody');

            if (!root) {
                return;
            }

            const observer = new MutationObserver(() => {
                if (!mount.isConnected) {
                    setMount(find());
                }
            });

            observer.observe(root, { childList: true, subtree: true });

            return () => observer.disconnect();
        }

        const root = document.getElementById('wpbody');

        if (!root) {
            return;
        }

        const observer = new MutationObserver(() => {
            const next = find();

            if (next) {
                observer.disconnect();
                setMount(next);
            }
        });

        observer.observe(root, { childList: true, subtree: true });

        return () => observer.disconnect();
    }, [find, mount]);

    return mount;
}

/**
 * The search line is sticky inside the scroller; two things follow from it.
 *
 * `--ff-controls-h` — its height, published on the scroller, which is where
 * the level view's *Top level* bar sticks (under it, not at the top) and how
 * far a row focused by the keyboard is kept below the scroller's top edge. A
 * number measured rather than restated in CSS: the line is 52px on a desktop
 * and 66 on a phone, and restating it would be a second copy of the control
 * sizes to drift.
 *
 * `is-stuck` — set while the line is pinned with rows passing under it, which
 * is when it carries the edge shadow the scroller's own top edge used to (the
 * opaque band covers that one). Not while Starred and Smart are still sliding
 * away above it: then the edge the content is cut at is the scroller's, and
 * the scroller's own shadow is the one showing.
 */
function useStickyControls(scrollRef: React.RefObject<HTMLDivElement>): void {
    useEffect(() => {
        const scroller = scrollRef.current;
        const controls = scroller?.querySelector<HTMLElement>(':scope > .folderfolio-rail__controls');

        if (!scroller || !controls) {
            return;
        }

        const measure = () => {
            scroller.style.setProperty('--ff-controls-h', `${controls.getBoundingClientRect().height}px`);
        };

        const stuck = () => {
            // Measured here as well: a sheet opened in a background tab gets
            // its ResizeObserver callback only when the tab is next painted,
            // and a scroll is the moment the number is needed.
            measure();

            const pinned =
                scroller.scrollTop > 0 &&
                controls.getBoundingClientRect().top - scroller.getBoundingClientRect().top < 0.5;

            controls.classList.toggle('is-stuck', pinned);
        };

        measure();
        stuck();

        const resize = new ResizeObserver(() => {
            measure();
            stuck();
        });

        resize.observe(controls);
        scroller.addEventListener('scroll', stuck, { passive: true });

        return () => {
            resize.disconnect();
            scroller.removeEventListener('scroll', stuck);
        };
    }, [scrollRef]);
}
