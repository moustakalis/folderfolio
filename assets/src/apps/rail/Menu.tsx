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
