/**
 * The rail's chrome: resize, collapse, and remembering both.
 *
 * Deliberately not React. Nothing here is application state — it is a width
 * and a boolean, and both have to be applied to DOM that the server already
 * rendered at the right size. Mounting a React root just to set a custom
 * property would mean the rail's geometry arrives one frame after the page,
 * which is precisely what rendering it server-side was for.
 *
 * The tree inside the rail is a separate bundle and does not know this file
 * exists.
 */

interface RailConfig {
    open: boolean;
    width: number;
    defaultWidth: number;
    minWidth: number;
    maxWidth: number;
    i18n: {
        width: string;
    };
}

declare global {
    interface Window {
        folderFolioRail?: RailConfig;
    }
}

/**
 * The collapsed rail's width, which is RailPreferences::TAB_WIDTH and the
 * 28px in _rail.css's .is-collapsed rule. It is not in RailConfig because the
 * server sends what the user chose, and this is a constant of the design.
 */
const TAB_WIDTH = 28;

const config = window.folderFolioRail;

const rail = document.getElementById('folderfolio-rail');
const handle = document.getElementById('folderfolio-rail-handle');

if (config && rail && handle) {
    start(config, rail, handle);
}

function start(config: RailConfig, rail: HTMLElement, handle: HTMLElement): void {
    let width = clamp(config.width);
    let open = config.open;

    /**
     * Announcements for the splitter.
     *
     * A separator that can be moved with the arrow keys is useless to a screen
     * reader unless the new value is spoken, and aria-valuenow on its own is
     * not reliably re-announced while the element already has focus.
     */
    // On <body>, not beside the rail: the rail's parent is the flex row that
    // lays out the admin page, and an extra child there is an extra flex item.
    // It is invisible in wp-admin, which styles .screen-reader-text out of
    // flow — but "invisible because another stylesheet happens to remove it
    // from the layout" is not where announcement furniture belongs.
    const live = document.createElement('div');
    live.className = 'screen-reader-text';
    live.setAttribute('aria-live', 'polite');
    document.body.appendChild(live);

    const collapseButton = rail.querySelector<HTMLElement>('[data-folderfolio-collapse]');
    const expandButton = rail.querySelector<HTMLElement>('[data-folderfolio-expand]');

    // ------------------------------------------------------------ applying

    function applyWidth(next: number, announce: boolean): void {
        width = clamp(next);

        rail.style.setProperty('--ff-rail-w', `${width}px`);
        gutter();
        handle.setAttribute('aria-valuenow', String(width));

        if (announce) {
            live.textContent = config.i18n.width.replace('%d', String(width));
        }
    }

    function applyOpen(next: boolean, moveFocus: boolean): void {
        open = next;

        rail.classList.toggle('is-collapsed', !open);
        document.body.classList.toggle('folderfolio-rail-collapsed', !open);
        gutter();
        handle.hidden = !open;

        collapseButton?.setAttribute('aria-expanded', String(open));
        expandButton?.setAttribute('aria-expanded', String(open));

        // Collapsing hides the button that was just pressed. Leaving focus on
        // a display:none element drops it to the top of the document, which
        // for a keyboard user means starting the page again.
        if (moveFocus) {
            (open ? collapseButton : expandButton)?.focus();
        }
    }

    /**
     * The width the rail occupies right now, on <body> for the rest of the
     * admin page — _rail.css indents core's footer by it so that the footer
     * neither sits under the rail nor takes the clicks aimed at Collapse.
     *
     * Rail.php prints the same property before first paint; this keeps it
     * true through a drag and through collapsing. Collapsed it is the tab
     * width, not the stored one, which is the difference between this and
     * --ff-rail-w.
     */
    function gutter(): void {
        document.body.style.setProperty('--ff-rail-gutter', `${open ? width : TAB_WIDTH}px`);
    }

    function clamp(value: number): number {
        if (!Number.isFinite(value)) {
            return config.defaultWidth;
        }

        return Math.max(config.minWidth, Math.min(config.maxWidth, Math.round(value)));
    }

    // ----------------------------------------------------------- persisting

    let pending: number | undefined;

    function persist(): void {
        window.clearTimeout(pending);

        // A drag fires a few hundred pointermove events. Without this each one
        // would be a write to user meta.
        pending = window.setTimeout(() => {
            void window.wp?.apiFetch({
                path: '/folderfolio/v1/preferences',
                method: 'POST',
                data: { rail: { open, width } },
            }).catch(() => {
                // A failed save is not worth interrupting anyone over: the
                // rail is already the size they dragged it to, and the next
                // change tries again. The only cost is that it reverts on the
                // next page load.
            });
        }, 400);
    }

    // -------------------------------------------------------------- resize

    let dragging = false;
    let startX = 0;
    let startWidth = 0;

    handle.addEventListener('pointerdown', (event: PointerEvent) => {
        if (event.button !== 0) {
            return;
        }

        dragging = true;
        startX = event.clientX;
        startWidth = width;

        // Capture, so the drag keeps working when the pointer outruns the
        // 5px handle — which it does immediately.
        handle.setPointerCapture(event.pointerId);
        handle.classList.add('is-dragging');
        document.body.classList.add('folderfolio-resizing');

        event.preventDefault();
    });

    handle.addEventListener('pointermove', (event: PointerEvent) => {
        if (!dragging) {
            return;
        }

        // Width at grab time plus how far the pointer has moved since, rather
        // than the pointer's distance from the rail's left edge. The two only
        // agree if the grab landed on the handle's exact left pixel; measured
        // from the edge, grabbing the handle anywhere else snaps the rail by
        // up to 5px before it starts following.
        //
        // Recomputed from the start each time rather than accumulated, so a
        // drag that runs past the clamp and comes back resumes where it left
        // off instead of drifting.
        applyWidth(startWidth + (event.clientX - startX), false);
    });

    function endDrag(event: PointerEvent): void {
        if (!dragging) {
            return;
        }

        dragging = false;

        handle.releasePointerCapture(event.pointerId);
        handle.classList.remove('is-dragging');
        document.body.classList.remove('folderfolio-resizing');

        persist();
    }

    handle.addEventListener('pointerup', endDrag);
    handle.addEventListener('pointercancel', endDrag);

    // Back to the designed width. The handoff's geometry is all measured at
    // 300px, so this is the way back to the state everything else assumes.
    handle.addEventListener('dblclick', () => {
        applyWidth(config.defaultWidth, true);
        persist();
    });

    handle.addEventListener('keydown', (event: KeyboardEvent) => {
        const step = event.shiftKey ? 64 : 16;

        const moves: Record<string, number> = {
            ArrowLeft: width - step,
            ArrowRight: width + step,
            Home: config.minWidth,
            End: config.maxWidth,
        };

        if (!(event.key in moves)) {
            return;
        }

        event.preventDefault();
        applyWidth(moves[event.key], true);
        persist();
    });

    // -------------------------------------------------------------- narrow

    /**
     * Below core's own mobile breakpoint the rail is not a column beside the
     * library but a bar above it, closed until it is tapped. The disclosure
     * block at the foot of _rail.css has the reasoning and the numbers.
     *
     * The state there is a body class and not `open`, because `open` is a
     * stored preference and a phone is not a preference. Nothing in this
     * section calls persist() or assigns to `open`, so a window that goes
     * back to full width goes back to whatever the rail was set to.
     */
    const narrow = window.matchMedia('(max-width: 782px)');
    const tab = rail.querySelector<HTMLElement>('.folderfolio-rail__tab');

    function peek(next: boolean, moveFocus: boolean): void {
        document.body.classList.toggle('folderfolio-rail-peek', next);

        // Both buttons describe the same region, and at this width the body
        // class is what that region's state is. applyOpen's value, which
        // comes from the preference, would be wrong in both directions.
        collapseButton?.setAttribute('aria-expanded', String(next));
        expandButton?.setAttribute('aria-expanded', String(next));

        // Same reason as in applyOpen: opening hides the control that was
        // pressed, and focus left on a display:none element goes to the top
        // of the document.
        if (moveFocus) {
            (next ? collapseButton : expandButton)?.focus();
        }
    }

    // On the row rather than on the button: the button is 24px wide and the
    // row is a 44px tap target. A click on the button bubbles to here, which
    // is why the button's own handler returns early at this width instead of
    // toggling a second time.
    tab?.addEventListener('click', () => {
        if (narrow.matches) {
            peek(true, true);
        }
    });

    /**
     * Crossing the breakpoint gives each side its own default back: the bar
     * closes, and the desktop gets the stored preference, unread and
     * unwritten in the meantime.
     *
     * The order is the same as the startup sequence at the foot of this
     * function, and for the same reason: applyOpen writes both aria-expanded
     * attributes from the preference, so on a phone peek has to have the last
     * word on them. Written the other way round — which it was — a window
     * dragged narrow ended up with a closed bar announcing itself as expanded.
     */
    narrow.addEventListener('change', () => {
        document.body.classList.remove('folderfolio-rail-peek');
        applyOpen(open, false);

        if (narrow.matches) {
            peek(false, false);
        }
    });

    // ------------------------------------------------------------ collapse

    collapseButton?.addEventListener('click', () => {
        if (narrow.matches) {
            peek(false, true);

            return;
        }

        applyOpen(false, true);
        persist();
    });

    expandButton?.addEventListener('click', () => {
        // The tab's listener above already has this click, on its way up.
        if (narrow.matches) {
            return;
        }

        applyOpen(true, true);
        persist();
    });

    // The server already rendered the correct state; this only re-applies it
    // so that the ARIA attributes and the body class cannot disagree with the
    // markup if either is ever changed in one place and not the other.
    applyOpen(open, false);
    applyWidth(width, false);

    // After applyOpen, not before: on a phone the bar is closed whatever the
    // preference says, so this has to be the last word on the two
    // aria-expanded attributes it just set.
    if (narrow.matches) {
        peek(false, false);
    }
}

export {};
