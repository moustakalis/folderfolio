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

import { Export } from './import/Export';
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
        /*
          Export sits beside the wizard rather than inside it: the wizard is
          four states watching something happen on the server, and this is one
          button. It is on the same tab because that tab is where folders
          enter and leave, not because it is a fifth step.
        */
        <QueryClientProvider client={client}>
            <Wizard />
            <Export />
        </QueryClientProvider>
    );
}
