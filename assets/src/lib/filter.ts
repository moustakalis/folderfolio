/**
 * Selecting a folder: what the library does about it.
 *
 * Lifted out of folder-tree.ts so the React rail and anything else that can
 * change the selection — the breadcrumb, a drill-down card, a deep link —
 * all go through one implementation. There is exactly one correct sequence
 * here (re-query, then rewrite the URL, and never navigate) and it should not
 * exist in two places.
 */

import { hasListTable, refreshListTable } from './list-refresh';

/** Must match MediaLibraryFilter::QUERY_VAR. */
export const FOLDER_QUERY_VAR = 'folderfolio_folder';

/**
 * Which folder the URL is asking for.
 *
 * `null` is no filter — All media. `0` is a real value, not an absence:
 * MediaLibraryFilter reads it as "media in no folder at all", which is the
 * Unassigned row. That is why this cannot use a truthiness check anywhere.
 */
export function folderFromUrl(): number | null {
    const raw = new URL(window.location.href).searchParams.get(FOLDER_QUERY_VAR);

    if (raw === null || raw === '') {
        return null;
    }

    const parsed = Number.parseInt(raw, 10);

    return Number.isNaN(parsed) ? null : parsed;
}

/** The URL this selection should produce. */
export function urlForFolder(folderId: number | null): string {
    const url = new URL(window.location.href);

    if (folderId === null) {
        url.searchParams.delete(FOLDER_QUERY_VAR);
    } else {
        url.searchParams.set(FOLDER_QUERY_VAR, String(folderId));
    }

    // Page 3 of the old folder is not page 3 of the new one.
    url.searchParams.delete('paged');

    return url.toString();
}

/**
 * Filter the library, and put the folder in the address bar.
 *
 * Never navigates in either mode. Grid re-queries the media frame's
 * collection; list swaps the table in place. The navigation at the bottom is
 * for a screen that is neither — and refreshListTable() falls back to one on
 * its own if its fetch fails, so the user always ends up seeing the folder
 * they asked for.
 */
export function applyFolderFilter(folderId: number | null): void {
    const url = urlForFolder(folderId);

    const collection = window.wp?.media?.frame?.content?.get?.()?.collection;

    if (collection?.props) {
        collection.props.set(FOLDER_QUERY_VAR, folderId === null ? '' : folderId);

        // The grid re-queries in place, so nothing else would update the
        // address bar — and a refresh or a shared link would lose the filter.
        window.history.replaceState({}, '', url);

        return;
    }

    if (hasListTable()) {
        void refreshListTable(url);

        return;
    }

    window.location.assign(url);
}
