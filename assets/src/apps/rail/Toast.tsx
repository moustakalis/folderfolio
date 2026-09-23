/**
 * The undo toast — screen 05.
 *
 * Deleting a folder opens no dialog. A confirm dialog asks a question before
 * anything has happened, when the user has the least information and the most
 * momentum; an undo asks nothing and is available after they can see the
 * result. It is also the only one of the two that helps when the delete was a
 * misclick rather than a mistaken decision.
 *
 * The 2px bar is the window draining. Hovering pauses it, because reading the
 * sentence takes time and the toast should not expire while you are reading
 * the thing it is asking you about.
 */

import { useEffect, useRef, useState } from 'react';

import { AlertIcon, UndoIcon } from './icons';
import { useRail } from './store';
import { t, tn } from '../../core/api';

/**
 * The grace period, in ms.
 *
 * From the settings screen, in seconds, clamped to the same range the form
 * enforces — the form is not the only way into that option, and a window of
 * zero is a delete with neither a dialog nor a recourse.
 *
 * Read once at module load rather than per toast: the value cannot change
 * while the page is open, and a toast whose bar is animating off one number
 * while its deadline was computed from another is the one bug this component
 * exists to avoid.
 */
export const UNDO_WINDOW = (() => {
    const seconds = window.folderFolio?.undoWindow;

    if (typeof seconds !== 'number' || !Number.isFinite(seconds)) {
        return 5000;
    }

    return Math.min(60, Math.max(3, Math.round(seconds))) * 1000;
})();

export function Toast({ onUndo, onExpire }: { onUndo: () => void; onExpire: () => void }) {
    const pending = useRail((s) => s.pendingUndo);
    const setPendingUndo = useRail((s) => s.setPendingUndo);

    const [remaining, setRemaining] = useState(UNDO_WINDOW);
    const paused = useRef(false);

    useEffect(() => {
        if (!pending) {
            return;
        }

        /**
         * One interval, not a CSS animation plus a timeout.
         *
         * The bar and the moment of expiry have to agree: a bar that reaches
         * the end while the folder is still recoverable, or a folder that goes
         * while the bar still shows time left, is worse than no bar at all.
         * Both come from this one number.
         */
        const tick = window.setInterval(() => {
            if (paused.current) {
                // Push the deadline along instead of tracking pause duration
                // separately. The remaining time simply stops going down.
                setPendingUndo({ ...pending, deadline: pending.deadline + 100 });

                return;
            }

            const left = pending.deadline - Date.now();

            setRemaining(Math.max(0, left));

            if (left <= 0) {
                window.clearInterval(tick);
                onExpire();
            }
        }, 100);

        return () => window.clearInterval(tick);
    }, [pending, onExpire, setPendingUndo]);

    if (!pending) {
        return null;
    }

    const seconds = Math.ceil(remaining / 1000);

    return (
        <div
            /*
              `folderfolio` as well as the modifier: the toast is portaled to
              <body>, which is outside every other FolderFolio element — and
              _tokens.css scopes the custom properties to `.folderfolio`.
              Without it `var(--ff-bar)` resolves to nothing and the sheet is
              transparent, which is how this first shipped.
            */
            className="folderfolio folderfolio-toast"
            // Assertive: it is time-limited, so a polite announcement queued
            // behind something else could arrive after the window has closed.
            role="alert"
            aria-live="assertive"
            /*
              over/out rather than enter/leave. They are what the browser
              actually dispatches; React synthesises enter and leave from
              them, and the synthetic pair cannot be triggered any other way —
              which also makes these the only version that can be tested
              without a real pointer.
            */
            onPointerOver={() => {
                paused.current = true;
            }}
            onPointerOut={(event) => {
                // over/out bubble, so moving between the toast's own children
                // fires out/over pairs. Only a move that leaves the toast
                // entirely should resume the clock.
                if (!event.currentTarget.contains(event.relatedTarget as Node)) {
                    paused.current = false;
                }
            }}
            onFocusCapture={() => {
                paused.current = true;
            }}
            onBlurCapture={() => {
                paused.current = false;
            }}
        >
            <span className="folderfolio-toast__icon">
                <UndoIcon size={18} />
            </span>

            <div className="folderfolio-toast__body">
                <p className="folderfolio-toast__text">
                    {pending.unassigned > 0
                        ? tn(
                              'deletedWithFile',
                              'deletedWithFiles',
                              pending.unassigned,
                              'Deleted “%1$s” — %2$s file moved to Unassigned',
                              'Deleted “%1$s” — %2$s files moved to Unassigned',
                              pending.name,
                              pending.unassigned
                          )
                        : t('deleted', 'Deleted “%s”', pending.name)}
                </p>

                {/*
                  Reduced motion gets the number instead of the bar. A
                  draining bar is motion for its own sake to someone who has
                  asked for less of it, but the information — how long is
                  left — is not decoration and has to survive.
                */}
                <div className="folderfolio-toast__bar" aria-hidden="true">
                    <span style={{ width: `${(remaining / UNDO_WINDOW) * 100}%` }} />
                </div>
                <span className="folderfolio-toast__count" aria-hidden="true">
                    {seconds}
                </span>
            </div>

            <button type="button" className="folderfolio-toast__undo" onClick={onUndo}>
                {t('undo', 'Undo')}
            </button>
        </div>
    );
}

/**
 * What the server refused — the undo toast's sheet, saying something else.
 *
 * Same corner, same dark sheet, same type: it is the rail talking about the
 * last thing the person did, which is exactly what the undo toast is. No clock
 * and no bar. A sentence explaining why something did not happen is read at
 * the reader's pace, and one that expired mid-sentence would fail WCAG 2.2.1
 * for no benefit; it goes when dismissed, or when the next write starts.
 *
 * `role="alert"` and a `key` on the id: the same refusal twice is two
 * announcements, which a live region only makes if the node is new.
 */
export function Notice() {
    const notice = useRail((s) => s.notice);
    const dismiss = useRail((s) => s.dismissNotice);

    if (!notice) {
        return null;
    }

    return (
        <div
            key={notice.id}
            className="folderfolio folderfolio-toast folderfolio-toast--notice"
            role="alert"
        >
            <span className="folderfolio-toast__icon">
                <AlertIcon size={18} />
            </span>

            <div className="folderfolio-toast__body">
                <p className="folderfolio-toast__text">{notice.message}</p>
            </div>

            {notice.action ? (
                <button
                    type="button"
                    className="folderfolio-toast__undo"
                    onClick={() => {
                        const run = notice.action?.run;

                        dismiss();
                        run?.();
                    }}
                >
                    {notice.action.label}
                </button>
            ) : null}

            {/* The undo button's class: the same control on the same sheet. */}
            <button type="button" className="folderfolio-toast__undo" onClick={dismiss}>
                {t('dismiss', 'Dismiss')}
            </button>
        </div>
    );
}
