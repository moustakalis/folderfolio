/**
 * The gallery block's editor bundle — screen 09, and design step 9b.
 *
 * Registration only: `blocks/gallery/block.json` is the metadata, registered
 * server-side, so the editor already knows this block's attributes and
 * defaults before this file runs. What is left is `edit`, and a `save` that
 * returns null because the block is server-rendered.
 *
 * WordPress's own packages are read off the `wp` global rather than imported.
 * That is the convention in this codebase — see core/api.ts — and it is what
 * keeps the bundle free of a second copy of @wordpress/components. The script
 * handles are declared in Blocks\Gallery, because a bundle that reads a global
 * cannot have that dependency observed for it by the build.
 */

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { Edit, type EditProps } from './gallery/Edit';
import { NAME } from './gallery/block';

const client = new QueryClient({
    defaultOptions: {
        queries: { retry: 1, refetchOnWindowFocus: false },
    },
});

const blocks = window.wp?.blocks;

if (blocks) {
    blocks.registerBlockType(NAME, {
        edit: (props: EditProps) => (
            <QueryClientProvider client={client}>
                <Edit {...props} />
            </QueryClientProvider>
        ),

        // Server-rendered: nothing goes into post content but the comment
        // delimiter and the attributes.
        save: () => null,
    });
}
