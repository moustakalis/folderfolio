/**
 * Placing files inside a folder by dropping them between tiles — tier 2
 * item 8, Nick's option B (board 66d5HKiPtdtNYTf7JWWBG5).
 *
 * ## Why this rides the drag that already exists
 *
 * A grid tile already drags: onto a rail folder, to file it (drag.ts). Core
 * has its own grid sorting — jQuery UI's, the gallery editor's — but it calls
 * `preventDefault()` on the press, which is what starts a native drag, so it
 * would have taken the drag to a folder away. So there is one drag with two
 * destinations: a rail folder files the files; the gap between two tiles of
 * the folder being viewed places them.
 *
 * Only in a folder, only from that folder, and only for someone with the
 * Organise ability. All media and Unassigned have no order to keep, and a
 * drag that started in another view is not an arrangement of this one.
 *
 * ## The two halves
 *
 * `planTileDrop()` is pure: the files being dragged, the tile under the
 * pointer and which half of it, in; the one request, or null, out.
 * `watchTileDrops()` is the DOM: the insertion bar and the drop.
 */

import { draggedFiles } from './drag';
import type { FilePlacement } from './queries';

export type Side = 'before' | 'after';

/**
 * The request a drop is, or null when it would change nothing.
 *
 * Dropped beside one of the files being dragged, nothing moves — the same
 * answer a folder dropped beside itself gets.
 */
export function planTileDrop(
    folderId: number,
    dragged: number[],
    anchor: number,
    side: Side
): FilePlacement | null {
    if (folderId <= 0 || dragged.length === 0 || dragged.includes(anchor)) {
        return null;
    }

    return { folderId, ids: dragged, place: side, anchor };
}

/**
 * Which side of a tile the pointer is on.
 *
 * Halves, left and right: the grid flows in rows, so "before" is the left of
 * a tile and "after" the right. The end of a row and the start of the next
 * are then the same gap, reached from either tile, which is what the bar
 * shows.
 */
export function sideOf(pointerX: number, left: number, width: number): Side {
    return pointerX < left + width / 2 ? 'before' : 'after';
}

const MARK = 'folderfolio-tile-drop';

export interface TileDropOptions {
    /** The folder being viewed, read at drop time. */
    folder: () => number | null;
    /** Whether this person may arrange it — the Organise ability. */
    allowed: () => boolean;
    drop: (placement: FilePlacement) => void;
}

/** Start listening. Returns a teardown. */
export function watchTileDrops(options: TileDropOptions): () => void {
    let marked: HTMLElement | null = null;
    let side: Side = 'before';

    const clear = () => {
        marked?.classList.remove(`${MARK}--before`, `${MARK}--after`);
        marked = null;
    };

    const tileFrom = (target: EventTarget | null): HTMLElement | null =>
        target instanceof Element
            ? target.closest<HTMLElement>('.attachments-browser li.attachment[data-id]')
            : null;

    /** The files, if this drag may be dropped between tiles here. */
    const eligible = (): number[] | null => {
        const files = draggedFiles();
        const folder = options.folder();

        if (!files || folder === null || folder <= 0 || !options.allowed()) {
            return null;
        }

        // From this folder's own grid, not from a view of another one.
        return files.sourceFolderId === folder ? files.ids : null;
    };

    const onDragOver = (event: DragEvent) => {
        const tile = tileFrom(event.target);

        if (!tile || eligible() === null) {
            clear();

            return;
        }

        // Accepting the drop is what stops the browser opening the image.
        event.preventDefault();

        if (event.dataTransfer) {
            event.dataTransfer.dropEffect = 'move';
        }

        const box = tile.getBoundingClientRect();
        const next = sideOf(event.clientX, box.left, box.width);

        if (tile !== marked || next !== side) {
            clear();
            marked = tile;
            side = next;
            tile.classList.add(`${MARK}--${side}`);
        }
    };

    const onDragLeave = (event: DragEvent) => {
        if (marked && !(event.relatedTarget instanceof Node && marked.contains(event.relatedTarget))) {
            clear();
        }
    };

    const onDrop = (event: DragEvent) => {
        const tile = tileFrom(event.target);
        const ids = eligible();
        const folder = options.folder();

        if (!tile || ids === null || folder === null) {
            clear();

            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const anchor = Number.parseInt(tile.dataset.id ?? '', 10);
        const placement = Number.isNaN(anchor)
            ? null
            : planTileDrop(folder, ids, anchor, sideOf(event.clientX, tile.getBoundingClientRect().left, tile.getBoundingClientRect().width));

        clear();

        if (placement) {
            options.drop(placement);
        }
    };

    document.addEventListener('dragover', onDragOver);
    document.addEventListener('dragleave', onDragLeave);
    document.addEventListener('drop', onDrop);
    document.addEventListener('dragend', clear);

    return () => {
        document.removeEventListener('dragover', onDragOver);
        document.removeEventListener('dragleave', onDragLeave);
        document.removeEventListener('drop', onDrop);
        document.removeEventListener('dragend', clear);
        clear();
    };
}
