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

/** Must match Admin\StartupFolder::QUERY_VAR. */
export const STARTUP_QUERY_VAR = 'folderfolio_startup';

/**
 * Whether this page view is one the startup folder sent us to.
 *
 * Set by the redirect and dropped by the next selection, so it is true exactly
 * once: on the arrival nobody asked for by clicking.
 */
export function arrivedFromStartupFolder(): boolean {
    return new URL(window.location.href).searchParams.has(STARTUP_QUERY_VAR);
}

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

/**
 * The URL this selection should produce.
 *
 * All media writes the parameter **empty rather than removing it**, and that
 * one character is what makes the breadcrumb's × work on a site with a startup
 * folder. `Admin\StartupFolder` redirects a bare arrival at `upload.php` — one
 * carrying no `folderfolio_folder` key at all — so if clearing the filter
 * deleted the key, the next reload would put the person straight back in the
 * folder they had just left. A control that appears to work and does not is
 * worse than no control.
 *
 * `?folderfolio_folder=` is therefore the spelling of *"all media, and I mean
 * it"*. Written whether or not a startup folder is configured, because a
 * behaviour that depends on a setting is a behaviour with two versions to
 * reason about, and both sides already handle the empty string by name: it
 * normalises to null here and in `MediaLibraryFilter::normalizeFolderId()`, so
 * the library is unfiltered and `posts_clauses` is untouched.
 */
export function urlForFolder(folderId: number | null): string {
    const url = new URL(window.location.href);

    url.searchParams.set(FOLDER_QUERY_VAR, folderId === null ? '' : String(folderId));

    // Page 3 of the old folder is not page 3 of the new one.
    url.searchParams.delete('paged');

    // The startup marker belongs to the arrival, not to what the person did
    // next — the same reason `paged` goes. Leaving it on would have the
    // breadcrumb explaining a folder the person had just chosen themselves.
    url.searchParams.delete(STARTUP_QUERY_VAR);

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

/**
 * Folder links in the library — the Folders column's paths, screen 06.
 *
 * `Admin\FoldersColumn` prints each membership as a real `<a href>` carrying
 * `?folderfolio_folder=<id>`, so the column works with scripts off, opens in
 * a new tab on a middle-click, and shows where it goes in the status bar.
 * With scripts on, a plain click should do what every other way of choosing a
 * folder does: filter in place, leaving the rail's state and the page's scroll
 * position alone.
 *
 * Delegated on the document because the table is replaced wholesale on every
 * folder change — see lib/list-refresh.ts. A listener bound to the links
 * themselves would survive exactly one click.
 *
 * Returns a teardown.
 */
export function watchFolderLinks(onSelect: (folderId: number | null) => void): () => void {
    const onClick = (event: MouseEvent) => {
        /*
         * Every one of these is a deliberate request for the browser's own
         * behaviour, and intercepting it would be taking something away:
         * a middle-click or ctrl/cmd-click opens a new tab, shift opens a
         * window, alt downloads. Only a plain left click is ours.
         */
        if (
            event.defaultPrevented
            || event.button !== 0
            || event.metaKey
            || event.ctrlKey
            || event.shiftKey
            || event.altKey
        ) {
            return;
        }

        const link = (event.target as Element | null)?.closest<HTMLElement>(
            '[data-folderfolio-folder]'
        );

        if (!link) {
            return;
        }

        const id = Number.parseInt(link.dataset.folderfolioFolder ?? '', 10);

        if (Number.isNaN(id)) {
            return;
        }

        event.preventDefault();
        onSelect(id);
    };

    document.addEventListener('click', onClick);

    return () => document.removeEventListener('click', onClick);
}
