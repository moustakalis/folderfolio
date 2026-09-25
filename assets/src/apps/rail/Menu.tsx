/**
 * A small popup menu: closes on Escape, on a click outside, and when focus
 * leaves it. All three, because each covers a case the others do not — Escape
 * for the keyboard, the outside click for the mouse, and focus leaving for Tab.
 *
 * Focus goes into the menu when it opens, and Escape puts it back on the
 * button that opened it. The second half was missing until the colour picker
 * was built: Escape closed the Sort menu and left focus on <body>, so the
 * next Tab started again from the top of wp-admin — a keyboard user who
 * glanced at a menu and changed their mind lost their place on the page. It
 * is fixed here rather than in the picker because both menus are this
 * component.
 *
 * Escape only, and decided at the moment of closing rather than read from
 * document.activeElement in the cleanup: by the time a passive effect's
 * cleanup runs React has already removed the menu, so focus is on <body> and
 * "was it still in the menu" can no longer be asked. The other two closes
 * must not restore anyway — a click outside and a Tab away are both the user
 * putting focus somewhere deliberately, and pulling it back to the button
 * would be the menu arguing with them.
 *
 * It lived in Toolbar.tsx until the rail's toolbar was dissolved on 22 Sep —
 * the folder actions moved onto the row and the global sort onto the search
 * line — and a file named after a component that no longer exists is a worse
 * home than a file named after this one.
 */

import { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';

import { useAnchoredPanel } from './useAnchoredPanel';

export function Menu({
    children,
    onClose,
    className,
}: {
    children: React.ReactNode;
    onClose: () => void;
    className?: string;
}) {
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const opener = document.activeElement as HTMLElement | null;
        let dismissed = false;

        ref.current?.querySelector<HTMLElement>('button')?.focus();

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                dismissed = true;
                onClose();
            }
        };

        const onPointer = (event: PointerEvent) => {
            if (!ref.current?.parentElement?.contains(event.target as Node)) {
                onClose();
            }
        };

        document.addEventListener('keydown', onKey, true);
        document.addEventListener('pointerdown', onPointer, true);

        return () => {
            document.removeEventListener('keydown', onKey, true);
            document.removeEventListener('pointerdown', onPointer, true);

            if (dismissed && opener?.isConnected) {
                opener.focus();
            }
        };
    }, [onClose]);

    return (
        <div
            ref={ref}
            className={`folderfolio-menu${className ? ` ${className}` : ''}`}
            role="menu"
            onBlur={(event) => {
                if (!event.currentTarget.contains(event.relatedTarget)) {
                    onClose();
                }
            }}
        >
            {children}
        </div>
    );
}

/**
 * The same menu, portaled to the body and pinned to the button that opened it.
 *
 * For the control line's two menus — Sort, and the folder menu below 782px —
 * since the narrow sheet became one scroller (Nick, 25 Sep, option A on board
 * NF7bQktuksgBSi4rCfoLvf). The control line is now a sticky band *inside* the
 * rail's scroller, and a menu left inside it has two problems a panel on the
 * body does not: the scroller's overflow clips it, and `position: sticky`
 * makes the band a stacking context, so below 782px core's media toolbar
 * (z-index 100) would paint over a menu that opens down across it — the
 * report of 24 Sep, back again. `useAnchoredPanel` already places, clamps,
 * flips, follows a scroll and closes on Escape or a pointer outside for the
 * row's ⋮; this keeps `Menu`'s other two duties: focus goes in when it opens
 * and back to the button on Escape, and Tab out of it closes it.
 */
export function AnchoredMenu({
    anchor,
    children,
    onClose,
    className,
    label,
}: {
    anchor: HTMLElement | null;
    children: React.ReactNode;
    onClose: () => void;
    className?: string;
    label?: string;
}) {
    // Hung from the trigger's right edge: both menus sit at the end of the
    // search line and open back across the rail, as they did inside it.
    const { ref, style } = useAnchoredPanel<HTMLDivElement>(anchor, onClose, 'end');

    useEffect(() => {
        ref.current?.querySelector<HTMLElement>('button')?.focus({ preventScroll: true });

        // On the window, ahead of the document: the panel's own Escape handler
        // is on the document and closes it, and with this listener beside it
        // on the document Escape left focus on <body> (25 Sep, the Sort menu:
        // the menu item lost focus and the button never got it). The window's
        // capture phase runs before the document's, so the button has focus
        // before anything is closed.
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && anchor?.isConnected) {
                anchor.focus();
            }
        };

        window.addEventListener('keydown', onKey, true);

        return () => window.removeEventListener('keydown', onKey, true);
    }, [anchor]);

    return createPortal(
        <div
            ref={ref}
            style={style}
            className={`folderfolio folderfolio-menu folderfolio-menu--anchored${className ? ` ${className}` : ''}`}
            role="menu"
            aria-label={label}
            onBlur={(event) => {
                const next = event.relatedTarget as Node | null;

                if (next && !event.currentTarget.contains(next) && !anchor?.contains(next)) {
                    onClose();
                }
            }}
        >
            {children}
        </div>,
        document.body
    );
}
