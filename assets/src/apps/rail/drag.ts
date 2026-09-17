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

/**
 * The ids being dragged, and where from.
 *
 * Module scope rather than React state because a `drop` handler has to read it
 * synchronously, and because `dataTransfer.getData()` is deliberately
 * unreadable during `dragover` — the moment the drop target needs to know how
 * many files are coming so it can preview the count.
 */
let payload: { ids: number[]; sourceFolderId: number | null } | null = null;

export function draggedPayload() {
    return payload;
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
    const selected = [
        ...document.querySelectorAll<HTMLElement>('li.attachment.selected[data-id]'),
    ]
        .map((el) => Number.parseInt(el.dataset.id ?? '', 10))
        .filter((n) => !Number.isNaN(n));

    const checked = [
        ...document.querySelectorAll<HTMLInputElement>(
            '#the-list tr .check-column input[type="checkbox"]:checked'
        ),
    ]
        .map((box) => Number.parseInt(box.value, 10))
        .filter((n) => !Number.isNaN(n));

    const marked = selected.length > 0 ? selected : checked;

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
        const id = attachmentIdFrom(event.target);

        if (id === null) {
            payload = null;

            return;
        }

        const ids = idsForDrag(id);
        const source = currentFolder();

        payload = {
            ids,
            // `0` is Unassigned — a real source, and one you can move out of.
            // `null` is All media, which is not a folder.
            sourceFolderId: source !== null && source >= 0 ? source : null,
        };

        // Replaces the browser's default payload (the thumbnail's URL), which
        // is what would otherwise be dropped on anything outside this page.
        event.dataTransfer?.setData('text/plain', `${ids.length} media file(s)`);
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
    };

    document.addEventListener('dragstart', onDragStart, true);
    document.addEventListener('dragend', onDragEnd, true);

    // Tiles with no <img> of their own — audio, some documents — are not
    // draggable until told. Re-applied on every grid re-render.
    const markDraggable = () => {
        document
            .querySelectorAll<HTMLElement>('li.attachment:not([draggable])')
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
