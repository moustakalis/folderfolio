/**
 * Dragging files out of the library and onto a folder.
 *
 * ## Why nothing here makes anything draggable
 *
 * The things being dragged are core's — `li.attachment` in the grid, a row in
 * the list table — and both already contain an `<img>`, which browsers make
 * draggable on their own. So the drag starts whether or not we ask for it;
 * what this module does is notice that it has started on an attachment and
 * replace the browser's default payload (an image URL) with the set of
 * attachment ids the user actually means.
 *
 * The one exception is a tile with no image at all. A `draggable` attribute is
 * added to those as they appear, through a MutationObserver — the grid is a
 * Backbone view that re-renders its whole list on every filter, so anything
 * set once on load would be gone by the second folder the user clicks.
 *
 * ## What a drop does
 *
 * A drag **moves** the files out of the folder currently being viewed, and
 * **adds** them when no folder is being viewed.
 *
 * That is one rule, not two behaviours: a move needs somewhere to move out of.
 * Looking at All media, the files may belong to folders that are not on
 * screen, and taking them out of those silently — because the user dragged
 * something into a different folder — is a change nobody asked for and cannot
 * see. So with no source folder the drop only adds. The UI says which: the
 * drop target previews the count it will have either way, and the toast after
 * a move names where the files came from.
 */

import { selectedAttachmentIds } from '../../lib/selection';
import { isMedia } from '../../core/api';

/**
 * The ids being dragged, and where from.
 *
 * Module scope rather than React state because a `drop` handler has to read it
 * synchronously, and because `dataTransfer.getData()` is deliberately
 * unreadable during `dragover` — the moment the drop target needs to know how
 * many files are coming so it can preview the count.
 */
export type DragPayload =
    | { kind: 'files'; ids: number[]; sourceFolderId: number | null }
    | {
          kind: 'folder';
          folderId: number;
          parentId: number | null;
          depth: number;
          name: string;
      };

let payload: DragPayload | null = null;

export function draggedPayload(): DragPayload | null {
    return payload;
}

/**
 * The files being dragged, or null when it is a folder — or nothing.
 *
 * Two payloads share one document, and the drop targets have to be deaf to
 * the one they do not serve. A folder row accepts files *and* is itself
 * draggable, so without this a folder dragged over a row would light the
 * frame that means "file these here".
 */
export function draggedFiles() {
    return payload?.kind === 'files' ? payload : null;
}

/** The folder being dragged, or null when it is files — or nothing. */
export function draggedFolder() {
    return payload?.kind === 'folder' ? payload : null;
}

/** The folder row a drag started on, read from the attributes Row emits. */
function folderDragFrom(target: EventTarget | null) {
    const el = target instanceof Element ? target : null;
    const row = el?.closest<HTMLElement>('[data-folderfolio-folder]');

    if (!row) {
        return null;
    }

    const folderId = Number.parseInt(row.dataset.folderfolioFolder ?? '', 10);

    if (Number.isNaN(folderId)) {
        return null;
    }

    const rawParent = row.dataset.folderfolioParent ?? '';
    const parentId = rawParent === '' ? null : Number.parseInt(rawParent, 10);

    return {
        kind: 'folder' as const,
        folderId,
        parentId: parentId === null || Number.isNaN(parentId) ? null : parentId,
        depth: Number.parseInt(row.dataset.folderfolioDepth ?? '0', 10) || 0,
        name: row.dataset.folderfolioName ?? '',
    };
}

/** The attachment id on a grid tile or a list row, or null. */
function attachmentIdFrom(target: EventTarget | null): number | null {
    const el = target instanceof Element ? target : null;

    if (!el) {
        return null;
    }

    // Grid: <li class="attachment" data-id="123">. List: <tr id="post-123">.
    const tile = el.closest<HTMLElement>('li.attachment[data-id]');

    if (tile) {
        const id = Number.parseInt(tile.dataset.id ?? '', 10);

        return Number.isNaN(id) ? null : id;
    }

    const row = el.closest<HTMLElement>('tr[id^="post-"]');

    if (row) {
        const id = Number.parseInt(row.id.slice('post-'.length), 10);

        return Number.isNaN(id) ? null : id;
    }

    return null;
}

/**
 * Everything the drag should carry.
 *
 * Dragging one of several selected files takes all of them; dragging one that
 * is not in the selection takes only it, and does not disturb the selection.
 * That is what every file manager does, and getting it wrong in either
 * direction — moving files the user did not mean, or moving one when they had
 * carefully picked twenty — is the kind of mistake a drag makes irreversibly.
 */
function idsForDrag(id: number): number[] {
    const marked = selectedAttachmentIds();

    return marked.includes(id) ? marked : [id];
}

/**
 * Start listening. Returns a teardown.
 *
 * `currentFolder` is read at drag time rather than captured, because the user
 * can change folders between page load and the drag — and the source folder is
 * what decides whether the drop moves or adds.
 */
export function watchDrags(currentFolder: () => number | null): () => void {
    const onDragStart = (event: DragEvent) => {
        /*
         * Folders first, and the order matters.
         *
         * This listener is on the document in the capture phase, so it sees
         * every drag on the page. Whichever of the two checks ran second
         * would null the other one's payload the moment it failed to match —
         * which is exactly what happened when a folder row was dragged and
         * only the attachment check existed.
         */
        const folder = folderDragFrom(event.target);

        if (folder) {
            payload = folder;

            event.dataTransfer?.setData('text/plain', folder.name);

            if (event.dataTransfer) {
                // Never 'copy'. A folder drag rearranges; it never duplicates.
                event.dataTransfer.effectAllowed = 'move';
            }

            document.body.classList.add('folderfolio-dragging');
            document.body.classList.add('folderfolio-dragging-folder');

            return;
        }

        const id = attachmentIdFrom(event.target);

        if (id === null) {
            payload = null;

            return;
        }

        const ids = idsForDrag(id);
        const source = currentFolder();

        payload = {
            kind: 'files',
            ids,
            // `0` is Unassigned — a real source, and one you can move out of.
            // `null` is All media, which is not a folder.
            sourceFolderId: source !== null && source >= 0 ? source : null,
        };

        // Replaces the browser's default payload (the thumbnail's URL), which
        // is what would otherwise be dropped on anything outside this page.
        event.dataTransfer?.setData('text/plain', `${ids.length} item(s)`);
        event.dataTransfer?.setData(
            'application/x-folderfolio',
            JSON.stringify(payload)
        );

        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'copyMove';
        }

        document.body.classList.add('folderfolio-dragging');
    };

    const onDragEnd = () => {
        payload = null;
        document.body.classList.remove('folderfolio-dragging');
        document.body.classList.remove('folderfolio-dragging-folder');
    };

    document.addEventListener('dragstart', onDragStart, true);
    document.addEventListener('dragend', onDragEnd, true);

    // Tiles with no <img> of their own — audio, some documents — are not
    // draggable until told. Re-applied on every grid re-render.
    //
    // A post list's rows (tier 3 item 12) have no thumbnail to start a drag
    // from — only the title link, which drags its URL — so the row itself is
    // made draggable, the way the media list's image makes its row. Not the
    // inline-edit row (`tr#edit-123`), which holds a form.
    const markDraggable = () => {
        document
            .querySelectorAll<HTMLElement>(
                isMedia() ? 'li.attachment:not([draggable])' : '#the-list > tr[id^="post-"]:not([draggable])'
            )
            .forEach((tile) => {
                tile.draggable = true;
            });
    };

    markDraggable();

    const observer = new MutationObserver(markDraggable);
    observer.observe(document.body, { childList: true, subtree: true });

    return () => {
        document.removeEventListener('dragstart', onDragStart, true);
        document.removeEventListener('dragend', onDragEnd, true);
        observer.disconnect();
        payload = null;
    };
}
