/**
 * The media frame's folder column — entry point, screen 10.
 *
 * Mounts nothing visible on load. The React root lives in a detached node and
 * the column portals itself into whichever media frame is open, through the
 * slot in lib/toolbar-slot.ts — so opening a picker costs a render, and a page
 * where nobody opens one costs a query for the tree and no DOM at all.
 *
 * A second entry rather than a branch inside apps/rail.tsx: the rail runs only
 * on upload.php and this runs only where it does not (see
 * Admin\MediaModalIntegration). They share every component and neither ships
 * the other's.
 */

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createRoot } from 'react-dom/client';

import { Frame } from './modal/Frame';
import { useRail } from './rail/store';
import { publishBrowsers } from '../lib/media-frame';
import { watchUploadTarget } from '../core/upload-target';

/**
 * Make the frame's collection reachable before any frame is built.
 *
 * `wp.media` is enqueued as a dependency, so it is on the page by the time
 * this runs; the retry exists for the case where another plugin defers it.
 */
if (!publishBrowsers()) {
    let tries = 0;
    const timer = setInterval(() => {
        if (publishBrowsers() || ++tries > 20) {
            clearInterval(timer);
        }
    }, 250);
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

const host = document.createElement('div');
host.className = 'folderfolio-frame-host';
host.hidden = true;
document.body.appendChild(host);

const client = new QueryClient({
    defaultOptions: {
        queries: { retry: 1, refetchOnWindowFocus: false },
    },
});

createRoot(host).render(
    <QueryClientProvider client={client}>
        <Frame />
    </QueryClientProvider>
);
