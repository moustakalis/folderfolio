/**
 * Filtering the list table without leaving the page.
 *
 * The grid is a Backbone app, so selecting a folder re-queries its collection
 * in place. The list table is server-rendered by WP_List_Table and has no such
 * handle, and v0.2.0 dealt with that by calling window.location.assign() — a
 * full page load for every click on a folder.
 *
 * That is not a slower version of the same thing. The document is replaced, so
 * the rail is rebuilt from nothing: whichever folders were expanded collapse,
 * the tree's scroll position is lost, and the screen flashes white between the
 * two renders. The point of a folder tree is moving around quickly, and a
 * reload undoes the tree's state on every move.
 *
 * ## Why fetch the admin page rather than call an API
 *
 * The obvious-looking alternative is to fetch rows from the REST API and
 * render them here. That would be wrong. The columns of this table are not
 * ours: core owns File, Author, Uploaded to and Date, FolderFolio adds
 * Folders, and any other plugin may have added its own through
 * manage_media_columns — along with row actions, inline data and whatever
 * markup those need. Re-rendering client-side means re-implementing all of it
 * and silently dropping everyone else's.
 *
 * So the request is for the page WordPress would have served anyway, and the
 * server keeps rendering every column. The cost is the same server-side render
 * the navigation cost; what is saved is the client teardown — the rail, the
 * tree's state and the scroll position all survive, because the document
 * never goes away.
 *
 * ## What makes this safe
 *
 * Replacing a tbody usually breaks a list table, because handlers bound
 * directly to rows go with it. WordPress 7.1's common.js binds the ones that
 * matter by delegation —
 *
 *     $(...).on('click', 'tbody > tr > .check-column :checkbox', …)
 *     $(...).on('click.wp-toggle-checkboxes', 'thead .check-column :checkbox, …')
 *
 * — so shift-click range selection and the select-all box keep working across
 * a swap. This was checked against the shipped common.min.js rather than
 * assumed; if a future WordPress moves either binding back onto the elements,
 * this is the thing that would quietly stop working.
 */

/** Must match MediaLibraryFilter::QUERY_VAR. */
const QUERY_VAR = 'folderfolio_folder';

/**
 * The regions a folder filter changes.
 *
 * The header and footer rows are in the list because every sortable column
 * link is built server-side from the current query string. Leave them alone
 * and sorting a filtered view would drop the folder and silently show
 * everything.
 *
 * `.search-box` is deliberately absent: it holds whatever the user has typed.
 *
 * ## What is *not* in this list any more, and why
 *
 * This used to replace `.tablenav.top`, `.tablenav.bottom` and
 * `.wp-filter .actions` whole. Those are the three places in a list table
 * where other plugins put things — `restrict_manage_posts` fires in the
 * filter bar, bulk-action options are appended to `.actions`, and both are
 * routinely enhanced by script afterwards. A server-rendered copy brings their
 * *markup* back and cannot bring their JavaScript back, so every folder click
 * silently undid a neighbour's work. That is us breaking them, on an ordinary
 * click, and it stopped being defensible the moment coexistence became the
 * plan rather than an accident.
 *
 * So the two `.tablenav`s are narrowed to `.tablenav-pages`, which is the only
 * part of them a folder filter changes — the count and the page links — and
 * `.bulkactions` is left alone. And `.wp-filter .actions` is gone entirely:
 * `syncFilterForm()` below already sets our own folder select from the URL,
 * which is what that line was added for, and nothing else in the filter bar
 * depends on which folder is showing.
 *
 * `#the-list` stays, because the rows *are* the answer to the question the
 * user asked. Nobody's row decorations can survive a different set of rows;
 * what they can have is the event at the end of `refreshListTable()`.
 */
const REGIONS = [
    '#the-list',
    '.wp-list-table thead tr',
    '.wp-list-table tfoot tr',
    '.tablenav.top .tablenav-pages',
    '.tablenav.bottom .tablenav-pages',
    '.subsubsub',
] as const;

/**
 * Dispatched on `document` once the table has been swapped.
 *
 * The honest answer to the part of this that cannot be narrowed away. A plugin
 * that decorates rows with script has no way to know the rows changed — there
 * is no core event for it, which is why every one of them decorates on ready
 * and never again. This is a place to listen.
 *
 * Named after the plugin, and carrying the URL, so that a listener can tell
 * which view it is looking at.
 */
export const REFRESHED = 'folderfolio:list-refreshed';

/**
 * One request at a time. Clicking three folders in a row starts three renders,
 * and without this the slowest one wins whenever it happens to land last.
 */
let inFlight: AbortController | undefined;

export function hasListTable(): boolean {
    return document.getElementById('the-list') !== null;
}

/**
 * Replace the list table with the one at `url`, and put `url` in the address
 * bar. Returns false if it had to fall back to a navigation.
 */
export async function refreshListTable(url: string): Promise<boolean> {
    const table = document.querySelector<HTMLElement>('.wp-list-table');

    if (!table || !hasListTable()) {
        window.location.assign(url);

        return false;
    }

    inFlight?.abort();
    const controller = new AbortController();
    inFlight = controller;

    // Written before the request, not after: the address bar should agree with
    // what the user just clicked immediately, and if the fetch fails we
    // navigate to this same URL anyway.
    window.history.replaceState({}, '', url);

    table.setAttribute('aria-busy', 'true');
    document.body.classList.add('folderfolio-list-refreshing');

    try {
        const response = await fetch(url, {
            credentials: 'same-origin',
            // Some hosts and security plugins treat a credentialed same-origin
            // fetch of an admin page as suspicious without it.
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller.signal,
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');

        // A login redirect, a fatal error or a maintenance page all return 200
        // with a body that is not this screen. Swapping pieces of one of those
        // in would leave a half-wrong table, so check before touching the DOM.
        if (!parsed.getElementById('the-list')) {
            throw new Error('the response was not a media list table');
        }

        for (const selector of REGIONS) {
            const next = parsed.querySelector(selector);
            const current = document.querySelector(selector);

            if (!current) {
                continue;
            }

            if (next) {
                /*
                 * A region that came back identical is a region nobody needs
                 * replaced.
                 *
                 * Measured, so that the comment says what is true rather than
                 * what sounds true: on a **folder change** this never skips
                 * anything. Every region left in the list carries the query
                 * string inside a link — the sort headers, the status links,
                 * the page links — so all of them legitimately differ.
                 *
                 * Where it pays is the **same-URL refresh**, which is the one
                 * that happens while somebody is working: assigning files to a
                 * folder, or deleting one, re-renders this table at the URL it
                 * is already on. There the rows change and the header, footer,
                 * status links and pagination do not — four regions that used
                 * to be rebuilt, with whatever anyone had put in them, on every
                 * assignment.
                 *
                 * String comparison rather than a DOM diff on purpose: the
                 * question is exactly "would the swap be a no-op?", and the
                 * markup is a few kilobytes.
                 */
                if (next.outerHTML === current.outerHTML) {
                    continue;
                }

                /*
                 * `#the-list` keeps its element and takes the new rows.
                 * Quick Edit binds to it directly — inline-edit-post.js's
                 * `$('#the-list').on('click', '.editinline', …)` — so the
                 * media list, which has no Quick Edit, never needed this;
                 * a post list (tier 3 item 12) that swapped the element lost
                 * Quick Edit on the first folder click.
                 */
                if (current.id === 'the-list') {
                    current.replaceChildren(...[...next.childNodes].map((node) => document.importNode(node, true)));

                    continue;
                }

                current.replaceWith(next);
            } else {
                // Present before, absent now — .subsubsub disappears when a
                // filter leaves no status links to show.
                current.remove();
            }
        }

        syncFilterForm(url);
        announce(parsed);

        document.dispatchEvent(new CustomEvent(REFRESHED, { detail: { url } }));

        return true;
    } catch (error) {
        if (controller.signal.aborted) {
            // Superseded by a later click. Its request owns the table now, and
            // it has already set aria-busy; leaving the flags alone here is
            // what keeps them correct.
            return false;
        }

        // Never quietly do nothing. The user asked for a folder; if it cannot
        // be shown in place, show it the slow way rather than leaving the
        // table on the previous one with a URL that disagrees.
        window.location.assign(url);

        return false;
    } finally {
        if (inFlight === controller) {
            inFlight = undefined;
            table.removeAttribute('aria-busy');
            document.body.classList.remove('folderfolio-list-refreshing');
        }
    }
}

/**
 * Keep the filter in the form that wraps the table.
 *
 * #posts-filter is a GET form: searching, changing the date filter or applying
 * a bulk action submits it, and only its inputs survive. Without this the
 * folder is dropped the moment anyone searches inside one — which is also true
 * of v0.2.0, where the reload hid it.
 */
function syncFilterForm(url: string): void {
    const form = document.querySelector<HTMLFormElement>('.wp-list-table')?.closest('form');

    if (!form) {
        return;
    }

    const value = new URL(url, window.location.origin).searchParams.get(QUERY_VAR);

    /*
     * In list mode the form already contains a real control with this name:
     * the folder select Admin\FolderSelect prints into the filter bar. Adding
     * a hidden input beside it would put two controls with one name in one GET
     * form, and the browser submits both — so searching inside a folder would
     * send folderfolio_folder twice and PHP would keep whichever came last.
     * Setting the select is both the fix and the right behaviour.
     */
    const existing = form.querySelector<HTMLElement>(`[name="${QUERY_VAR}"]`);

    if (existing instanceof HTMLSelectElement) {
        if ([...existing.options].some((option) => option.value === (value ?? ''))) {
            existing.value = value ?? '';
        }

        return;
    }

    let input = existing instanceof HTMLInputElement ? existing : null;

    if (value === null) {
        input?.remove();

        return;
    }

    if (!input) {
        input = document.createElement('input');
        input.type = 'hidden';
        input.name = QUERY_VAR;
        form.appendChild(input);
    }

    input.value = value;
}

/**
 * Say what happened.
 *
 * A sighted user sees the table change. With a reload a screen reader
 * announced the new page; now that nothing navigates, the count has to be
 * spoken deliberately or the filter is silent.
 */
function announce(parsed: Document): void {
    const count = parsed.querySelector('.displaying-num')?.textContent?.trim();

    if (count) {
        window.wp?.a11y?.speak(count, 'polite');
    }
}
