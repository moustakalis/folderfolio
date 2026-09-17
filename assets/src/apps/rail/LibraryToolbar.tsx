/**
 * FolderFolio's two controls inside WordPress's own filter row.
 *
 * Rendered through lib/toolbar-slot.ts, which owns each element and keeps
 * putting it back as core rebuilds the toolbar around it. See that file for
 * why a plain portal into `.tablenav.top` or `.media-toolbar` does not work.
 *
 * Two slots rather than one, because the two controls do not always belong
 * together:
 *
 *  - the **select** is grid-only here. List mode's is printed by PHP so that
 *    it filters with scripts off and comes back correct from the server on
 *    every list refresh — see FolderSelect.tsx. It also has to be able to
 *    disappear with core's own filters when the grid enters "Bulk select",
 *    which is a thing the trigger beside it must not do.
 *  - the **trigger** exists in both modes, and in grid has to outlive core
 *    hiding the row it sits in.
 */

import { createPortal } from 'react-dom';

import { AddToFolder } from './AddToFolder';
import { FolderSelect, useNativeFolderSelect } from './FolderSelect';
import type { FolderNode } from './queries';
import { bulkSlotPlace, filterSlotPlace, useToolbarSlot } from '../../lib/toolbar-slot';

export function LibraryToolbar({ nodes }: { nodes: FolderNode[] }) {
    // The list-mode select is not ours to render, but it is ours to keep in
    // step. Called unconditionally: in grid mode it finds nothing and costs a
    // querySelectorAll against an empty result.
    useNativeFolderSelect();

    const filterSlot = useToolbarSlot(filterSlotPlace, 'filter');
    const bulkSlot = useToolbarSlot(bulkSlotPlace, 'bulk');

    return (
        <>
            {filterSlot ? createPortal(<FolderSelect nodes={nodes} />, filterSlot) : null}
            {bulkSlot ? createPortal(<AddToFolder nodes={nodes} />, bulkSlot) : null}
        </>
    );
}
