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
 *  - the **trigger** — Add to folder — exists in both modes, and in grid has
 *    to outlive core hiding the row it sits in.
 *
 * There used to be a second trigger, `Move to folder`. The verb is now a
 * choice inside the flyout; AddToFolder's docblock says why.
 */

import { useEffect } from 'react';
import { createPortal } from 'react-dom';

import { AddToFolder } from './AddToFolder';
import { FilterDisclosure } from './FilterDisclosure';
import { FolderPicker } from './FolderPicker';
import { FolderSelect, useNativeFolderSelect } from './FolderSelect';
import type { FolderNode } from './queries';
import {
    bulkSlotPlace,
    disclosureSlotPlace,
    filterSlotPlace,
    pickerSlotPlace,
    useToolbarSlot,
} from '../../lib/toolbar-slot';
import { t } from '../../core/api';

/**
 * Give core's search field the placeholder its two modes disagree about.
 *
 * The same input, `#media-search-input`, is drawn two ways. In list mode it is
 * `name="s"` inside the `#posts-filter` GET form, next to a visible
 * `Search Media` submit button, so core hides the `<label>` as
 * `.screen-reader-text` rather than say the words twice. In grid mode there is
 * no form and no button — the input has no `name` at all and filters the
 * Backbone collection on keyup — so core makes the label **visible** instead,
 * to the left of the field.
 *
 * The result is that the same control renders as label-then-field in one mode
 * and field-then-button in the other: not merely a different width, a different
 * reading direction. `_toolbar.css` hides grid's label the way list already
 * hides its own; this puts the words back where the board draws them, inside
 * the field.
 *
 * Set here rather than in PHP because grid's field does not exist until core's
 * Backbone toolbar has rendered — which is the same event the slots below wait
 * for, so it is the same signal.
 */
function useSearchPlaceholder(...signals: unknown[]): void {
    useEffect(() => {
        const input = document.querySelector<HTMLInputElement>(
            '#wpbody-content #media-search-input'
        );

        if (input && !input.placeholder) {
            input.placeholder = t('searchMedia', 'Search media');
        }
        // Re-run whenever core rebuilds the toolbar: the node it rebuilt may be
        // a new one, and a placeholder set on the old node went with it.
    }, signals);
}

export function LibraryToolbar({ nodes }: { nodes: FolderNode[] }) {
    // The list-mode select is not ours to render, but it is ours to keep in
    // step. Called unconditionally: in grid mode it finds nothing and costs a
    // querySelectorAll against an empty result.
    useNativeFolderSelect();

    const filterSlot = useToolbarSlot(filterSlotPlace, 'filter');
    const bulkSlot = useToolbarSlot(bulkSlotPlace, 'bulk');
    // Placed in both modes and at every width; CSS decides where it shows.
    const disclosureSlot = useToolbarSlot(disclosureSlotPlace, 'disclosure');
    // The same, and for the same reason: it stands in for the select below
    // the breakpoint, and the breakpoint is a container query.
    const pickerSlot = useToolbarSlot(pickerSlotPlace, 'picker');

    useSearchPlaceholder(filterSlot, bulkSlot);

    return (
        <>
            {filterSlot ? createPortal(<FolderSelect nodes={nodes} />, filterSlot) : null}
            {bulkSlot ? createPortal(<AddToFolder nodes={nodes} />, bulkSlot) : null}
            {disclosureSlot ? createPortal(<FilterDisclosure />, disclosureSlot) : null}
            {pickerSlot ? createPortal(<FolderPicker nodes={nodes} />, pickerSlot) : null}
        </>
    );
}
