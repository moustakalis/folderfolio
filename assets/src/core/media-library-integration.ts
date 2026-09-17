/**
 * Keeps the media grid's own frame in step with the folder selection.
 *
 * It used to also render an info bar above the grid reading "Filtering by
 * folder #3" with a Clear button — an id, not a name, and the only way out of
 * a filter. The breadcrumb replaces both: it names the whole path, and every
 * level of it including All media is a way back out.
 */

import { hasListTable, refreshListTable } from '../lib/list-refresh';

interface FolderSelectedDetail {
    folderId: number | null;
}

interface FolderFolioMediaEvent extends CustomEvent<FolderSelectedDetail> {}

const eventName = 'folderfolio:folder-selected';

function emitMediaFilter(folderId: number | null): void {
    window.wp?.media?.frame?.trigger('folderfolio:filter', { folderId });
}

function currentFolderFromUrl(): number | null {
    const raw = new URL(window.location.href).searchParams.get('folderfolio_folder');

    if (raw === null || raw === '') {
        return null;
    }

    const parsed = Number.parseInt(raw, 10);

    return Number.isNaN(parsed) ? null : parsed;
}

function start(): void {
    window.dispatchEvent(new CustomEvent('folderfolio:media-ready'));

    // folder-tree.ts dispatches on window. A listener on document is never in
    // the propagation path of a window-dispatched event, which is why this
    // module previously did nothing at all.
    window.addEventListener(eventName, (event: Event) => {
        const { detail } = event as FolderFolioMediaEvent;

        emitMediaFilter(detail?.folderId ?? null);
    });

    /*
     * A drop changed which files are in the folder being viewed, so the
     * library is now showing a stale answer to a question it was not asked
     * again. In grid mode the collection re-queries itself; in list mode the
     * table is re-fetched the same way a folder change re-fetches it.
     */
    window.addEventListener('folderfolio:library-changed', () => {
        const collection = window.wp?.media?.frame?.content?.get?.()?.collection as
            | { props?: { trigger?: (event: string) => void } }
            | undefined;

        if (collection?.props?.trigger) {
            collection.props.trigger('change');

            return;
        }

        if (hasListTable()) {
            void refreshListTable(window.location.href);
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
} else {
    start();
}
