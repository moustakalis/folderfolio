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
            <Rail />
        </QueryClientProvider>
    );
}
