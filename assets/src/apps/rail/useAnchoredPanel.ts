/**
 * A panel portaled to the body and pinned to the control that opened it.
 *
 * ## Why this is a hook rather than two copies
 *
 * The bulk `Add to folder` flyout and the narrow-width folder picker are
 * different panels with different jobs, and exactly the same *mechanics*:
 * measure the trigger in viewport coordinates, clamp to the window, follow a
 * scroll or a resize, and close on Escape, on a pointer outside, or when the
 * trigger goes away. Both are portaled to the body for the same reason — in
 * list mode `.tablenav` has its own overflow and a 300px panel inside it is
 * clipped; in grid mode the toolbar is a flex row with the same problem.
 *
 * The rules are fiddly enough that a second copy would drift. The flip in
 * particular is not obvious: a left-aligned panel runs off the right edge of a
 * narrow window, and the first version of it read `offsetWidth` before the
 * panel had been laid out, so the clamp used 300 when the panel was 320 and
 * the last 20px sat off screen.
 *
 * ## Why the position is state and not a style written directly
 *
 * The panel has to be measured before it can be placed, and measuring it means
 * it is already in the document. Rendered at its default position first, it
 * paints once at 0,0 — a flash in the top-left corner of the screen before it
 * jumps to the trigger. So the caller renders it at -9999px until `at` is
 * known, which is one layout pass later and never painted.
 */

import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import type { CSSProperties } from 'react';

/** How far the panel is kept from the window's edges. */
const MARGIN = 8;

/** The width assumed for the very first clamp, before the panel has a box. */
const ASSUMED_WIDTH = 300;

/**
 * The room a panel would like underneath its trigger.
 *
 * Not the room it needs — the room below which it is worth looking the other
 * way. A header, a search field and eight or nine rows. Measured at a 700px
 * window with the filter panel open: 184px was left below the trigger, which
 * the panel honoured and filled with **three rows**, while 465px sat unused
 * above it. Flipping only for a panel that barely fits is too late; the test
 * is which side is better, bounded by not flipping when the natural side is
 * already comfortable.
 */
const PREFERRED_ROOM = 320;

/** …and the floor, below which a clamp would leave nothing readable. */
const MIN_ROOM = 180;

export interface AnchoredPanel<T extends HTMLElement> {
    /** Put this on the panel's own element. */
    ref: React.RefObject<T>;
    /** …and this on its style. Off screen until the first measurement lands. */
    style: CSSProperties;
}

/**
 * Pin `ref` under `anchor`, and call `onClose` when the user is done with it.
 *
 * `onClose` is called on Escape, on a pointerdown outside both the panel and
 * the trigger, and never on a click inside either — a click on the trigger is
 * the trigger's own business, since that is what toggles the panel shut.
 */
export function useAnchoredPanel<T extends HTMLElement>(
    anchor: HTMLElement | null,
    onClose: () => void
): AnchoredPanel<T> {
    const ref = useRef<T>(null);
    const [at, setAt] = useState<
        { top?: number; bottom?: number; left: number; room: number } | null
    >(null);

    useLayoutEffect(() => {
        if (!anchor) {
            return;
        }

        const place = () => {
            const box = anchor.getBoundingClientRect();
            const width = ref.current?.offsetWidth ?? ASSUMED_WIDTH;
            const left = Math.max(
                MARGIN,
                Math.min(box.left, window.innerWidth - width - MARGIN)
            );

            /*
             * How much room there is, and which way to open.
             *
             * Measured: the folder picker opened under a trigger sitting in a
             * three-row filter panel and ran 23px off the bottom of a 900px
             * window — and that was the *generous* case, a desktop window with
             * the panel only half full. On a phone the trigger is lower and
             * the window is shorter, so the list was the part that fell off.
             *
             * The room is handed to CSS as `--ff-room` rather than used to set
             * a height here: the panel knows which of its parts should give —
             * its scrolling list — and this hook should not have to know the
             * shape of every panel that uses it.
             */
            const below = window.innerHeight - box.bottom - MARGIN;
            const above = box.top - MARGIN;

            /*
             * A panel that fits the window is shown whole (24 Sep, Nick: "to
             * reach delete you should scroll"). Its own height is known —
             * it is rendered, off screen, before this runs, and scrollHeight
             * is its content whatever `--ff-room` clamps it to. Below if it
             * fits there, above if it fits there, and when it fits neither
             * side of its trigger but does fit the window, slid up until its
             * foot meets the window's, over the trigger's row — the way a
             * desktop context menu does. The ⋮ menu from a row halfway down an
             * 818px window was 459px against 346 below and 420 above.
             *
             * A panel taller than the window — a folder list — keeps the rule
             * below, which picks the better side and scrolls.
             */
            // Its borders too: the panels are border-box, and a max-height
            // of the content alone scrolled them by 2px.
            const panel = ref.current;
            const natural = panel ? panel.scrollHeight + panel.offsetHeight - panel.clientHeight : 0;
            const usable = window.innerHeight - 2 * MARGIN;

            if (natural > 0 && natural <= usable && natural > below) {
                setAt(
                    natural <= above
                        ? { bottom: window.innerHeight - box.top + 2, left, room: natural }
                        : { top: window.innerHeight - MARGIN - natural, left, room: natural }
                );

                return;
            }

            const flip = below < PREFERRED_ROOM && above > below;
            const room = Math.max(MIN_ROOM, flip ? above : below);

            /*
             * A flipped panel is placed by its **bottom** edge, not its top.
             *
             * The first version subtracted the panel's own height from the
             * trigger's top — and read that height before `--ff-room` had
             * clamped it, so it placed a 386px panel that then rendered 186px
             * tall and sat 200px too low, overlapping the very trigger it was
             * flipping away from. Measured.
             *
             * Anchoring the bottom needs no height at all, so there is nothing
             * to read too early and nothing to correct on a second pass.
             */
            setAt(
                flip
                    ? { bottom: window.innerHeight - box.top + 2, left, room }
                    : { top: box.bottom + 2, left, room }
            );
        };

        place();

        window.addEventListener('resize', place);
        // Captured, because the thing that scrolls is usually an ancestor
        // rather than the window — the list table's own overflow, most often.
        window.addEventListener('scroll', place, true);

        return () => {
            window.removeEventListener('resize', place);
            window.removeEventListener('scroll', place, true);
        };
    }, [anchor]);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                // Stopped, so that Escape closing this panel does not also
                // close the media modal the panel may be sitting inside.
                event.stopPropagation();
                onClose();
            }
        };

        const onPointer = (event: PointerEvent) => {
            const target = event.target as Node;

            if (!ref.current?.contains(target) && !anchor?.contains(target)) {
                onClose();
            }
        };

        document.addEventListener('keydown', onKey, true);
        document.addEventListener('pointerdown', onPointer, true);

        return () => {
            document.removeEventListener('keydown', onKey, true);
            document.removeEventListener('pointerdown', onPointer, true);
        };
    }, [onClose, anchor]);

    return {
        ref,
        style: {
            // Off screen until the first measurement lands, so the panel is
            // never painted at 0,0 and never clamped to a guess.
            top: at?.top === undefined ? undefined : `${at.top}px`,
            bottom: at?.bottom === undefined ? undefined : `${at.bottom}px`,
            left: at ? `${at.left}px` : '-9999px',
            ...(at === null ? { top: '-9999px' } : {}),
            ['--ff-room' as string]: at ? `${at.room}px` : undefined,
        } as CSSProperties,
    };
}
