/**
 * A place in someone else's toolbar that React can hold on to.
 *
 * ## The problem
 *
 * The two controls this step adds — the folder select and the bulk
 * "Add to folder" flyout — belong in WordPress's own filter row, and neither
 * filter row survives being used:
 *
 *  - list mode's `.tablenav.top` is **replaced wholesale** on every folder
 *    change, because lib/list-refresh.ts swaps in the server's freshly
 *    rendered copy (which is the point: core owns those columns and controls);
 *  - grid mode's `.media-toolbar` is a Backbone view that re-renders itself
 *    when the frame changes mode.
 *
 * A React portal into either one is destroyed the first time that happens.
 * React does not notice — it still holds a container that is no longer in the
 * document — so the control does not come back, and nothing reports an error.
 *
 * ## What this does
 *
 * Keeps one element per control, created once and never recreated, and puts it
 * back where it belongs whenever the surrounding markup is rebuilt. React portals into
 * *that* element, so the portal's container is never replaced; it is only ever
 * moved, and its subtree moves with it. React never unmounts, so the flyout's
 * open state, its checkboxes and its in-flight request all survive a list
 * refresh happening underneath it.
 *
 * Moving a node between parents does not remount it, and does not reset an
 * `<input>` inside it — the one thing that would make this approach a lie.
 */

import { useEffect, useState } from 'react';

export interface Place {
    parent: Element;
    /** Insert before this, or append when null. */
    before: Element | null;
}

/**
 * Where the folder select goes.
 *
 * Grid only: in list mode it is printed by PHP, into the filter bar, so that
 * it works with scripts off — see Admin\FolderSelect.
 *
 * After the date filter, before "Bulk select", which is where screen 03 draws
 * it. It is placed before the bulk slot rather than after it so that the two
 * keep their designed order however they are created.
 */
export function filterSlotPlace(): Place | null {
    const grid = document.querySelector('.media-toolbar-secondary');

    if (!grid) {
        return null;
    }

    return {
        parent: grid,
        before:
            grid.querySelector('.folderfolio-slot--bulk')
            ?? grid.querySelector('.select-mode-toggle-button'),
    };
}

/**
 * Where the narrow-width `Filter` disclosure goes.
 *
 * First among the filters in both modes, because it *is* the filters once the
 * container is narrow enough to collapse them — a control that stands for a
 * group belongs where the group began, not after it.
 *
 * Grid: straight after the view switch, which is the one control that stays on
 * the line at every width.
 *
 * List: inside `.wp-filter`, before the media-type select. Not inside
 * `.actions`, which holds only the date and folder selects and core's `Filter`
 * submit — the disclosure speaks for the media-type select too, so it has to
 * sit outside the group it is not a member of.
 *
 * Returning null above the breakpoint is deliberately *not* how this is
 * scoped: the width that decides it belongs to a container query, which script
 * cannot read. The button is always placed and CSS decides whether it is on
 * screen. See FilterDisclosure.tsx.
 */
export function disclosureSlotPlace(): Place | null {
    const grid = document.querySelector('.media-toolbar-secondary');

    if (grid) {
        /*
         * Anchored to core's first filter label, not to `view-switch`'s next
         * sibling.
         *
         * The first version asked for `viewSwitch.nextElementSibling` — which,
         * the moment this slot is placed there, **is this slot**. So every
         * pass computed `before: slot`, the equality check below could never
         * be satisfied, and `insertBefore(slot, slot)` ran: a legal no-op that
         * still removes and re-adds the node, which wakes the observer, which
         * does it again. Measured at a steady 1 Hz, forever.
         *
         * A detach between `mousedown` and `mouseup` means the browser fires
         * **no `click` at all**, so the button looked dead to a mouse while a
         * scripted `.click()` — synchronous, entirely between two churns —
         * always worked. That is exactly how it was reported.
         *
         * The anchor has to be a node that is always core's, never ours. The
         * first `label` is the one immediately after the view switch, so this
         * lands in the same place and can never be self-referential.
         */
        return {
            parent: grid,
            before: grid.querySelector('label, select.attachment-filters'),
        };
    }

    const list = document.querySelector('#wpbody-content .wp-filter .filter-items');

    if (list) {
        return { parent: list, before: list.querySelector('.actions') };
    }

    return null;
}

/**
 * Where the bulk "Add to folder" trigger goes.
 *
 * List: inside the bulk-actions group, before Apply. Screen 06 draws exactly
 * that order — Bulk actions, Add to folder, Apply.
 *
 * Grid: after the date filter, before "Bulk select" — the same spot screen 03
 * gives it. That reads as an odd place for a bulk action until you watch what
 * core does when the user presses "Bulk select": it sets `display: none`
 * inline on every child of this toolbar except "Delete permanently", the
 * mode toggle, and the spinner. So in select mode the filters collapse to
 * nothing and what is left, in source order, is
 *
 *     Delete permanently | Add to folder | Cancel
 *
 * — the two bulk actions side by side, which is where a bulk action belongs.
 * One position, correct in both states, and no second slot to keep in step.
 * (The CSS has to opt this slot out of core's hiding; see `_toolbar.css`.)
 */
export function bulkSlotPlace(): Place | null {
    const grid = document.querySelector('.media-toolbar-secondary');

    if (grid) {
        return { parent: grid, before: grid.querySelector('.select-mode-toggle-button') };
    }

    const bulk = document.querySelector('.tablenav.top .bulkactions');

    if (bulk) {
        return { parent: bulk, before: bulk.querySelector('input[type="submit"]') };
    }

    return null;
}

/**
 * An element that keeps itself in `place`, for as long as the component using
 * it is mounted. Null until there is somewhere to put it.
 */
export function useToolbarSlot(place: () => Place | null, name: string): HTMLElement | null {
    // Lazily, once: the identity of this node is the whole mechanism. A new
    // element per render would be a new portal container per render, which is
    // the remount this module exists to avoid.
    const [slot] = useState(() => {
        const el = document.createElement('span');
        // The modifier is not decoration: grid mode's select-mode CSS has to
        // be able to name the bulk slot without naming the other one.
        el.className = `folderfolio folderfolio-slot folderfolio-slot--${name}`;

        return el;
    });

    const [placed, setPlaced] = useState(false);

    useEffect(() => {
        let queued: ReturnType<typeof setTimeout> | undefined;

        /*
         * Keeping focus across a re-attach.
         *
         * Detaching a subtree blurs whatever inside it had focus, and the
         * browser does not put it back — it drops to <body>. In list mode that
         * happens on every folder change and after every bulk add, so a
         * keyboard user who has just pressed Add-to-folder is returned to the
         * top of the document, mid-task, by a refresh they did not ask for and
         * cannot see.
         *
         * The element cannot be read at move time: by then the detach has
         * already happened and activeElement is <body>. So it is recorded as
         * focus arrives, and restored afterwards — but only when focus is
         * sitting on <body>, which is what "it was lost" looks like and what
         * "the user moved it somewhere else" never does.
         */
        let lastFocused: HTMLElement | null = null;

        const remember = (event: FocusEvent) => {
            lastFocused = event.target as HTMLElement;
        };

        const restoreFocus = () => {
            if (
                lastFocused
                && document.activeElement === document.body
                && slot.contains(lastFocused)
            ) {
                lastFocused.focus();
            }
        };

        slot.addEventListener('focusin', remember);

        const settle = () => {
            queued = undefined;

            const target = place();

            if (!target) {
                setPlaced(false);

                return;
            }

            /*
             * A `before` of the slot itself means "where you already are".
             *
             * `insertBefore(slot, slot)` is legal and does nothing visible,
             * but it still removes and re-adds the node — so it wakes this
             * observer, which schedules another pass, which does it again. A
             * `place()` that names a position relative to a sibling will
             * return this the moment the slot becomes that sibling, and the
             * loop it produces is invisible except that every click on
             * anything inside the slot is silently dropped.
             */
            const before = target.before === slot ? slot.nextElementSibling : target.before;

            // Already exactly where it should be. Checked rather than
            // re-inserted every time, because re-inserting would be a DOM
            // mutation, which would wake this observer, which would
            // re-insert — a loop that costs nothing visible and never stops.
            if (slot.parentElement === target.parent && slot.nextElementSibling === before) {
                setPlaced(true);

                return;
            }

            target.parent.insertBefore(slot, before);
            restoreFocus();
            setPlaced(true);
        };

        const schedule = () => {
            if (queued === undefined) {
                queued = setTimeout(settle, 0);
            }
        };

        const observer = new MutationObserver(schedule);
        observer.observe(document.body, { subtree: true, childList: true });

        settle();

        return () => {
            observer.disconnect();
            slot.removeEventListener('focusin', remember);

            if (queued !== undefined) {
                clearTimeout(queued);
            }

            slot.remove();
        };
    }, [slot, place]);

    return placed ? slot : null;
}
