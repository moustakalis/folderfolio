/**
 * The migration wizard's entry point — screen 07.
 *
 * React, where the settings tabs beside it are plain PHP, and the difference
 * is not taste. A settings form is eleven controls posted once; this is four
 * states, a preview fetched on demand, and a run polled until it finishes. The
 * thing that makes it a wizard rather than a form is that it watches something
 * happening on the server.
 */

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createRoot } from 'react-dom/client';

import { Wizard } from './import/Wizard';

const mount = document.getElementById('folderfolio-import-app');

if (mount) {
    const client = new QueryClient({
        defaultOptions: {
            queries: {
                retry: 1,
                refetchOnWindowFocus: false,
            },
        },
    });

    createRoot(mount).render(
        <QueryClientProvider client={client}>
            <Wizard />
        </QueryClientProvider>
    );
}
