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

/** Must match MediaLibraryFilter::SMART_VAR — a smart folder's id (tier 3 item 13). */
export const SMART_QUERY_VAR = 'folderfolio_smart';

/**
 * The smart folder in the address bar, or null. A positive id or nothing:
 * there is no "unassigned" smart folder.
 */
export function smartFromUrl(): number | null {
    const parsed = Number.parseInt(new URL(window.location.href).searchParams.get(SMART_QUERY_VAR) ?? '', 10);

    return Number.isNaN(parsed) || parsed <= 0 ? null : parsed;
}

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
export function urlForFolder(folderId: number | null, smartId: number | null = null): string {
    const url = new URL(window.location.href);

    url.searchParams.set(FOLDER_QUERY_VAR, folderId === null ? '' : String(folderId));

    // A smart folder travels beside the folder key rather than instead of it:
    // the key present and empty is what keeps the startup redirect from
    // sending a person looking at a smart folder somewhere else.
    if (smartId === null) {
        url.searchParams.delete(SMART_QUERY_VAR);
    } else {
        url.searchParams.set(SMART_QUERY_VAR, String(smartId));
    }

    // Page 3 of the old folder is not page 3 of the new one.
    url.searchParams.delete('paged');

    // The startup marker belongs to the arrival, not to what the person did
    // next — the same reason `paged` goes. Leaving it on would have the
    // breadcrumb explaining a folder the person had just chosen themselves.
    url.searchParams.delete(STARTUP_QUERY_VAR);

    return url.toString();
}

/**
 * Keep the folder in the address bar when core's media grid rewrites it.
 *
 * The grid's Backbone router has a route for plain `upload.php` whose handler
 * empties the search field and fires `input`, and the search field's handler
 * navigates to `upload.php` — no query — whenever the field is empty. On
 * WordPress 7.x that runs when the grid starts its history, so an arrival at
 * `upload.php?folderfolio_folder=12` showed folder 12 under an address bar
 * that said `upload.php`: a reload lost the folder, and a shared link was
 * nothing (found through CI on 24 Sep: WordPress Playground is 7.x, the
 * container's rig 6.8.2; the dev site's "something rewrites location.search
 * after load" was this).
 *
 * Only the bare `upload.php` navigation gets our keys back. Core's own
 * `?item=` and `?search=` URLs are matched by routes whose values run to the
 * end of the string, so a key of ours after theirs would be read as part of
 * the search term on a reload; those stay core's.
 */
export function keepFilterInGridUrl(): void {
    type Navigate = (fragment: string, options?: unknown) => unknown;
    const Router = (window.wp?.media?.view as { MediaFrame?: { Manage?: { Router?: { prototype: Record<string, unknown> } } } } | undefined)
        ?.MediaFrame?.Manage?.Router;
    const proto = Router?.prototype;

    if (!proto || typeof proto.navigate !== 'function' || proto.folderfolioKeepsUrl) {
        return;
    }

    const navigate = proto.navigate as Navigate;

    proto.navigate = function (this: unknown, fragment: string, options?: unknown) {
        if (fragment === 'upload.php') {
            const here = new URL(window.location.href).searchParams;
            const kept = new URLSearchParams();

            for (const key of [FOLDER_QUERY_VAR, SMART_QUERY_VAR]) {
                const value = here.get(key);

                if (value !== null) {
                    kept.set(key, value);
                }
            }

            const query = kept.toString();

            return navigate.call(this, query === '' ? fragment : `${fragment}?${query}`, options);
        }

        return navigate.call(this, fragment, options);
    };
    proto.folderfolioKeepsUrl = true;
}

/**
 * A media collection shows a folder in the order the server sent it.
 *
 * Core's attachment collections sort themselves in the browser: the grid's
 * `date` orderby gives its collection a comparator, and every page the server
 * sends is put back into date order on arrival. So a folder's own file order
 * (tier 1 item 2) and its Custom positions (tier 2 item 8) reached the grid
 * correctly ordered and were shown newest first — measured on 23 Sep, a
 * folder set to Name, A to Z. List mode was right all along; it renders on
 * the server.
 *
 * The fix is a comparator of our own, inside a folder only: each model's
 * place in the server's answer. Not an orderby change — `post__in`, the one
 * core leaves unsorted, also removes the comparator that keeps core's jQuery
 * UI sorting switched off in the grid, and that sorting takes the mouse on
 * press and would have killed the drag to a folder (found trying it). With a
 * comparator in place core's sorting stays off, and outside a folder core's
 * own comparator is put back.
 */
const RANK = 'folderfolioRank';

interface RankedModel {
    get(key: string): unknown;
    set(key: string, value: unknown, options?: { silent?: boolean }): void;
}

interface MediaCollection {
    comparator?: unknown;
    props: {
        get(key: string): unknown;
        on(event: string, callback: () => void): void;
    };
}

interface WpMediaModels {
    Query?: { prototype: { parse: (response: unknown, options: unknown) => RankedModel[]; length?: number } };
    Attachments?: { comparator?: unknown };
}

function mediaModels(): WpMediaModels | undefined {
    return (window.wp as unknown as { media?: { model?: WpMediaModels } } | undefined)?.media?.model;
}

/**
 * Each model a query receives is stamped with its place in the answer.
 *
 * On the Query's parse, because that is the one point the server's order
 * still exists: `set()` sorts right after. The offset is how many the query
 * already holds, so page two starts at 80. Done once per page load.
 */
function stampServerOrder(): boolean {
    const Query = mediaModels()?.Query;

    if (!Query) {
        return false;
    }

    const proto = Query.prototype as { parse: (response: unknown, options: unknown) => RankedModel[]; folderfolioRanked?: boolean };

    if (!proto.folderfolioRanked) {
        const parse = proto.parse;

        proto.parse = function (this: { length?: number }, response: unknown, options: unknown) {
            const offset = this.length ?? 0;
            const models = parse.call(this, response, options);

            models.forEach((model, index) => model.set(RANK, offset + index, { silent: true }));

            return models;
        };
        proto.folderfolioRanked = true;
    }

    return true;
}

/**
 * By place in the server's answer; a model with none — an upload that has
 * just finished — first, which is where the server puts a new file too.
 */
export function byServerOrder(a: RankedModel, b: RankedModel): number {
    const ra = a.get(RANK);
    const rb = b.get(RANK);
    const x = typeof ra === 'number' ? ra : -Infinity;
    const y = typeof rb === 'number' ? rb : -Infinity;

    return x === y ? 0 : x < y ? -1 : 1;
}

/**
 * Keep a media collection in the server's order whenever it shows a folder.
 *
 * Runs on `change:folderfolio_folder`, which Backbone fires before the
 * `change` that re-queries, so the comparator is in place before the first
 * model of the new answer arrives.
 */
export function keepServerOrder(collection: MediaCollection): void {
    if (!stampServerOrder()) {
        return;
    }

    const coreComparator = mediaModels()?.Attachments?.comparator;

    const sync = () => {
        const raw = collection.props.get(FOLDER_QUERY_VAR);
        const folderId = raw === '' || raw === null || raw === undefined ? null : Number(raw);

        if (folderId !== null && folderId > 0) {
            collection.comparator = byServerOrder;
        } else if (collection.comparator === byServerOrder) {
            collection.comparator = coreComparator;
        }
    };

    collection.props.on(`change:${FOLDER_QUERY_VAR}`, sync);
    sync();
}

interface UploadView extends MediaCollection {
    add?: (model: unknown) => unknown;
    remove?: (model: unknown) => unknown;
    get?: (model: unknown) => unknown;
    validator?: (model: unknown) => boolean;
}

/** Every grid collection on the page — the library's, or each picker's. */
const uploadViews = new Set<UploadView>();

/**
 * A grid that should show uploads made while it is on screen.
 *
 * Found verifying tier 2 item 9, and older than it: **a file uploaded while a
 * folder is selected never appeared in the grid** — no tile, no progress —
 * until the next re-query. `wp.media.model.Query` watches `wp.Uploader.queue`
 * only when every query arg is one of seven core knows (`media-models.js`:
 * "There are no filters for other properties"), and `folderfolio_folder` is
 * not one of them. It is in the props from the first selection on, `''`
 * included, so once a folder had been chosen no grid showed an upload at all.
 */
export function showUploads(collection: UploadView): void {
    uploadViews.add(collection);
}

/**
 * Put an upload in the grid it was made in — called as each file is queued.
 *
 * `folder` is what the upload parameter said when the file was added: an id,
 * or `''` for none. A grid on that folder shows it; All media and Unassigned
 * show an unfiled one; a grid on any other folder does not. Files from a
 * dropped directory show in the folder they were dropped on while they
 * upload — they are what the person just dropped — and in their own folders
 * from the next query on, which is the server's answer, not this one.
 *
 * A plain `add()`, not `observe()`: an observed collection re-judges a model
 * on every change, and the finished upload is the same model a later query
 * returns — so a filter keyed to where it was dropped hid it from the very
 * folder it had been filed in. Added once, here, it stays until the grid next
 * re-queries, when what the server says replaces it. Core's own `validator` is
 * still asked, so an image does not appear under a *Video* filter.
 */
export function showUpload(model: unknown, folder: string): void {
    for (const view of uploadViews) {
        const raw = view.props.get(FOLDER_QUERY_VAR);
        const viewing = raw === '' || raw === null || raw === undefined ? '' : String(raw);
        const smart = view.props.get(SMART_QUERY_VAR);
        // All media holds every file; Unassigned, the unfiled ones. A smart
        // folder is the server's to answer: an upload may or may not match
        // its rules, and the next query says which.
        const shows =
            smart !== '' && smart !== null && smart !== undefined
                ? false
                : viewing === ''
                  ? true
                  : viewing === '0'
                    ? folder === ''
                    : viewing === folder;

        if (!shows || typeof view.add !== 'function') {
            continue;
        }

        if (typeof view.validator === 'function' && !view.validator(model)) {
            continue;
        }

        view.add(model);
    }
}

/**
 * Draw a finished upload's tile again — called once each file is up.
 *
 * Core's tile takes its `aria-label` from the title when the view is *built*
 * (`media-views.js`, `attributes()`), and an upload has no title yet, so it is
 * built as "uploading…" and stays that way: `render()` refreshes the inside of
 * the tile, not its attributes. Core's own uploads escape it because their
 * query re-adds the model; one put in a grid by `showUpload()` does not. On
 * the dev site the label is also the caption under the thumbnail, so it read
 * "uploading…" under a finished file. Taking the model out and putting it
 * back builds the tile from the finished model.
 */
export function redrawUpload(model: unknown): void {
    for (const view of uploadViews) {
        if (typeof view.get === 'function' && view.get(model) && view.remove && view.add) {
            view.remove(model);
            view.add(model);
        }
    }
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
export function applyFolderFilter(folderId: number | null, smartId: number | null = null): void {
    const url = urlForFolder(folderId, smartId);

    const collection = window.wp?.media?.frame?.content?.get?.()?.collection;

    if (collection?.props) {
        // Both at once, so the grid asks the server one question, not two.
        collection.props.set({
            [FOLDER_QUERY_VAR]: folderId === null ? '' : folderId,
            [SMART_QUERY_VAR]: smartId === null ? '' : smartId,
        });

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
