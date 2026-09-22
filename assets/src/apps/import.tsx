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

import { BulkCreate } from './import/BulkCreate';
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
          Three siblings, not one flow. The wizard is four states watching
          something happen on the server; the other two are a button each.
          They share this tab because it is where folders enter and leave,
          which is also the order they are in — from another plugin, from a
          list, and out to a file.

          Bulk create goes above the export for the same reason: both are
          doors, and the two that bring folders *in* belong together.
        */
        <QueryClientProvider client={client}>
            <Wizard />
            <BulkCreate />
            <Export />
        </QueryClientProvider>
    );
}
