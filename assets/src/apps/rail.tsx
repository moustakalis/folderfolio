/**
 * The rail app's entry point.
 *
 * Mounts into the node Rail.php renders. Nothing here imports `react`
 * directly — the alias in tools/esbuild.mjs points it at WordPress's own
 * bundle, and the .asset.php this build writes declares `wp-element` so the
 * enqueue order is right without anyone maintaining a dependency array.
 */

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createRoot } from 'react-dom/client';

import { Rail } from './rail/Rail';
import { useRail } from './rail/store';
import { watchUploadTarget } from '../core/upload-target';

/**
 * Where the breadcrumb and the drill-down cards go.
 *
 * Created here rather than printed by PHP, because the only correct place for
 * it — just below core's page heading, inside .wrap — has not been parsed yet
 * at the hook where the rail's own markup is printed. The node is empty until
 * React fills it, so creating it a frame later costs nothing visually, unlike
 * the rail itself whose width has to be right before first paint.
 *
 * .wp-header-end is core's own marker for "the heading is done"; it is what
 * admin notices anchor to, so it is the one landmark guaranteed to be there
 * and in the right place on both library modes.
 */
function contentMount(): HTMLElement | null {
    const existing = document.getElementById('folderfolio-content');

    if (existing) {
        return existing;
    }

    const anchor =
        document.querySelector('#wpbody-content .wrap .wp-header-end') ??
        document.querySelector('#wpbody-content .wrap h1');

    if (!anchor) {
        return null;
    }

    const el = document.createElement('div');
    el.id = 'folderfolio-content';
    el.className = 'folderfolio folderfolio-content';
    anchor.insertAdjacentElement('afterend', el);

    return el;
}

/**
 * Uploads follow the folder — screen 10's footer line, made true.
 *
 * Here rather than inside a component: it is not rendering anything, it has
 * to be running before a frame is opened, and it is the same two lines in
 * both entries. See core/upload-target.ts.
 */
watchUploadTarget(
    (listener) => useRail.subscribe((state) => listener(state.selectedId)),
    useRail.getState().selectedId
);

const mount = document.getElementById('folderfolio-rail-app');

if (mount) {
    const client = new QueryClient({
        defaultOptions: {
            queries: {
                // One retry, not three. A failed folder request on wp-admin is
                // almost always a permission or a plugin conflict, and three
                // rounds of exponential backoff just delay the error message
                // that would have told someone what was wrong.
                retry: 1,
                refetchOnWindowFocus: false,
            },
        },
    });

    createRoot(mount).render(
        <QueryClientProvider client={client}>
            <Rail contentMount={contentMount()} />
        </QueryClientProvider>
    );
}
