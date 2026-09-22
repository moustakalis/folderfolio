/**
 * Dropping a folder: where the rule goes, and what the drop resolves to.
 *
 * ## Why there is no React state in here
 *
 * `dragover` fires continuously. Tree.tsx does not subscribe to focus or
 * selection precisely because re-rendering it re-creates every Row beneath
 * it — 126ms per keystroke at 5,000 rows, measured. A marker held in state
 * would do that on every pointer move of a drag, which is worse. So the rule
 * is one element that is always mounted, and this moves it by writing to its
 * style directly.
 *
 * ## The rule's position is the whole answer
 *
 * A frame says "into this container" and belongs to files (useDropTarget). A
 * rule says "at this position" — and because its left offset is the depth it
 * will land at, the same indicator answers both halves of the question:
 * which parent, and which slot. Nesting is not a special case; it is the rule
 * one indent further in.
 *
 * ## The gap that means four things
 *
 * Between a depth-2 row and a depth-0 row, four depths are all legal. The
 * pointer's x picks, snapped to one indent, clamped to the range the gap
 * actually allows: no shallower than the row below, no deeper than one inside
 * the row above.
 */

import { useCallback, useRef } from 'react';

import { draggedFolder } from './drag';
import { useReorderFolders, type FolderNode } from './queries';
import { useRail } from './store';

interface Visible {
    node: FolderNode;
    depth: number;
}

/** The flat slot the folder will occupy, and how deep it sits in it. */
interface Target {
    index: number;
    depth: number;
}

export function useFolderDrop(visible: Visible[], roots: FolderNode[]) {
    const markerRef = useRef<HTMLSpanElement>(null);
    const target = useRef<Target | null>(null);
    const reorder = useReorderFolders();
    const setSort = useRail((s) => s.setSort);
    const expand = useRail((s) => s.expand);

    const hide = useCallback(() => {
        target.current = null;

        if (markerRef.current) {
            markerRef.current.hidden = true;
        }
    }, []);

    /**
     * Which gap the pointer is in, and how deep the rule should sit in it.
     */
    const resolve = useCallback(
        (event: React.DragEvent): (Target & { top: number; left: number }) | null => {
            const el = event.target instanceof Element ? event.target : null;
            const rowEl = el?.closest<HTMLElement>('.folderfolio-row');

            if (!rowEl) {
                return null;
            }

            const id = Number.parseInt(rowEl.dataset.folderfolioFolder ?? '', 10);
            const at = visible.findIndex((v) => v.node.id === id);

            if (at === -1) {
                return null;
            }

            const rect = rowEl.getBoundingClientRect();
            const after = event.clientY >= rect.top + rect.height / 2;

            // The flat index the folder would take, which is also the gap.
            const index = after ? at + 1 : at;
            const above = visible[index - 1];
            const below = visible[index];

            // No shallower than the row below — it owns the level the gap
            // continues into. No deeper than one inside the row above, which
            // is the deepest thing the gap can mean.
            const min = below ? below.depth : 0;
            const max = above ? above.depth + 1 : 0;

            // Measured from the row's own left edge: every row's box starts
            // there whatever its depth, and 12px + 24n is where its content
            // begins.
            const wanted = Math.round((event.clientX - rect.left - 12) / 24);
            const depth = Math.max(min, Math.min(max, wanted));

            return {
                index,
                depth,
                top: after ? rowEl.offsetTop + rowEl.offsetHeight : rowEl.offsetTop,
                left: 12 + 24 * depth,
            };
        },
        [visible]
    );

    /**
     * The parent and the whole sibling list a drop resolves to.
     *
     * Whole list, because that is what POST /folders/reorder takes — and it
     * takes it because a list cannot half-apply the way a delta can.
     */
    const plan = useCallback(
        (index: number, depth: number, draggedId: number) => {
            let parent: FolderNode | null = null;

            if (depth > 0) {
                for (let i = index - 1; i >= 0; i -= 1) {
                    if (visible[i].depth === depth - 1) {
                        parent = visible[i].node;

                        break;
                    }
                }

                if (!parent) {
                    return null;
                }
            }

            if (parent && parent.id === draggedId) {
                return null;
            }

            const siblings = parent ? parent.children : roots;
            const flatIndex = new Map(visible.map((v, i) => [v.node.id, i]));

            // How many of them the gap falls after. A collapsed parent has no
            // visible children, so every count is 0 and the folder lands
            // first — which is the only slot the pointer can be naming when
            // there is nothing on screen to sit between.
            const before = siblings.filter((s) => {
                const at = flatIndex.get(s.id);

                return s.id !== draggedId && at !== undefined && at < index;
            }).length;

            const ids = siblings.map((s) => s.id).filter((id) => id !== draggedId);
            ids.splice(before, 0, draggedId);

            return { parentId: parent ? parent.id : null, ids, siblings };
        },
        [visible, roots]
    );

    const onDragOver = useCallback(
        (event: React.DragEvent) => {
            const folder = draggedFolder();

            if (!folder) {
                return;
            }

            // Without preventDefault on *every* dragover the browser refuses
            // the drop, whatever dragenter said.
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';

            const next = resolve(event);
            const marker = markerRef.current;

            if (!next || !marker) {
                hide();

                return;
            }

            target.current = { index: next.index, depth: next.depth };
            marker.style.top = `${next.top}px`;
            marker.style.left = `${next.left}px`;
            marker.hidden = false;
        },
        [resolve, hide]
    );

    const onDragLeave = useCallback(
        (event: React.DragEvent) => {
            // Only when the pointer has actually left the tree, not when it
            // crossed from one row to the next inside it.
            const to = event.relatedTarget;

            if (to instanceof Node && event.currentTarget.contains(to)) {
                return;
            }

            hide();
        },
        [hide]
    );

    const onDrop = useCallback(
        (event: React.DragEvent) => {
            const folder = draggedFolder();
            const at = target.current;

            hide();

            if (!folder || !at) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            const resolved = plan(at.index, at.depth, folder.folderId);

            if (!resolved) {
                return;
            }

            // Dropped where it already was. Worth catching here rather than
            // sending: the server would accept it and write the same order
            // back, and the tree would flicker through an invalidate for
            // nothing.
            const current = resolved.siblings.map((s) => s.id);
            const unchanged =
                resolved.parentId === folder.parentId
                && current.length === resolved.ids.length
                && current.every((id, i) => id === resolved.ids[i]);

            if (unchanged) {
                return;
            }

            // The arrangement is only visible under Custom, and the user has
            // just made one — so the view follows the act rather than asking
            // them to go and find the menu.
            setSort('custom');

            // Dropped into a collapsed folder: open it, or the row appears to
            // have vanished.
            if (resolved.parentId !== null) {
                expand(resolved.parentId);
            }

            reorder.mutate({ parentId: resolved.parentId, ids: resolved.ids });
        },
        [plan, hide, setSort, expand, reorder]
    );

    return {
        markerRef,
        handlers: { onDragOver, onDragLeave, onDrop },
    };
}
