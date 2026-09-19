/**
 * The breadcrumb — screen 03, overflow on screen 11.
 *
 * Answers two questions at once: where am I, and what is one level up. That is
 * why the overflow rule is not "keep the first three": root and the current
 * folder are never dropped, the parent is kept as long as it fits, and
 * whatever is in the middle collapses into a button that lists it.
 *
 * "As long as it fits" is measured, not guessed at a depth threshold. A
 * 300px-wide rail leaves a different amount of room than a collapsed one, and
 * the same path fits or does not depending on the window — which is exactly
 * what screen 11 shows at three widths.
 */

import { useLayoutEffect, useRef, useState } from 'react';

import { CloseIcon } from './icons';
import { useRail } from './store';
import { t } from '../../core/api';

export interface Crumb {
    /** `null` is All media — the root of every path. */
    id: number | null;
    label: string;
}

export function Breadcrumb({ crumbs }: { crumbs: Crumb[] }) {
    const select = useRail((s) => s.select);
    const ref = useRef<HTMLElement>(null);
    const [hidden, setHidden] = useState(0);
    const [menuOpen, setMenuOpen] = useState(false);

    /**
     * Bumped whenever the box changes size, purely to make the fit effect run
     * again.
     *
     * Resetting `hidden` is not enough on its own: when it is already 0 React
     * bails out of the update, nothing re-renders, and the effect that does
     * the measuring never fires — so widening the rail left the breadcrumb
     * overflowing with every crumb still shown. It needs a value that always
     * changes.
     */
    const [measured, setMeasured] = useState(0);

    /**
     * How many middle crumbs may be dropped.
     *
     * Everything except the root and the current folder, in order from the
     * root outward — so the parent, being last in that list, is the last to
     * go. With two crumbs nothing is droppable and the row simply clips,
     * which is better than hiding half a one-level path.
     */
    const droppable = Math.max(0, crumbs.length - 2);

    /**
     * The path as a value, not as an array identity.
     *
     * `crumbs` is rebuilt on every render of the parent, so using it as an
     * effect dependency means the effect runs every render. The first version
     * of this did exactly that: the reset effect fired on each pass and put
     * `hidden` back to 0 the moment the fit loop raised it, so the breadcrumb
     * measured itself forever and never dropped a single crumb.
     */
    const signature = crumbs.map((c) => `${c.id}:${c.label}`).join('|');

    // A different path starts the measurement over.
    useLayoutEffect(() => {
        setHidden(0);
    }, [signature]);

    /**
     * So does a different width — but only a *different* one. ResizeObserver
     * fires once when it starts observing, and on every re-render that
     * follows a drop; resetting on those would be the same loop again.
     */
    useLayoutEffect(() => {
        const el = ref.current;

        if (!el) {
            return;
        }

        let last = el.clientWidth;

        const observer = new ResizeObserver(() => {
            if (el.clientWidth !== last) {
                last = el.clientWidth;
                setHidden(0);
                setMeasured((n) => n + 1);
            }
        });

        observer.observe(el);

        return () => observer.disconnect();
    }, []);

    /**
     * Drop one more, then measure again.
     *
     * Raising `hidden` re-renders, which runs this effect again, until either
     * the row fits or there is nothing left that may be dropped. In a layout
     * effect, so none of the intermediate states is painted.
     */
    useLayoutEffect(() => {
        const el = ref.current;

        if (el && el.scrollWidth > el.clientWidth + 1 && hidden < droppable) {
            setHidden(hidden + 1);
        }
    }, [hidden, droppable, signature, measured]);

    const first = crumbs[0];
    const rest = crumbs.slice(1);
    const collapsed = rest.slice(0, hidden);
    const shown = rest.slice(hidden);

    return (
        <nav className="folderfolio-crumbs" aria-label={t('breadcrumb', 'Folder path')} ref={ref}>
            <ol className="folderfolio-crumbs__list">
                <Item crumb={first} current={crumbs.length === 1} onSelect={select} />

                {collapsed.length > 0 ? (
                    <li className="folderfolio-crumbs__item">
                        <span className="folderfolio-crumbs__sep" aria-hidden="true">/</span>

                        {/*
                          A real button at 26 × 22, not a text ellipsis. The
                          hidden levels are reachable, which is the whole point
                          of collapsing them rather than truncating the line.
                        */}
                        <button
                            type="button"
                            className="folderfolio-crumbs__more"
                            aria-expanded={menuOpen}
                            aria-label={t('showHiddenLevels', 'Show the levels in between')}
                            onClick={() => setMenuOpen((open) => !open)}
                            onBlur={(event) => {
                                if (!event.currentTarget.parentElement?.contains(event.relatedTarget)) {
                                    setMenuOpen(false);
                                }
                            }}
                        >
                            …
                        </button>

                        {menuOpen ? (
                            <ul className="folderfolio-crumbs__menu">
                                {collapsed.map((crumb, depth) => (
                                    <li key={String(crumb.id)}>
                                        <button
                                            type="button"
                                            className="folderfolio-crumbs__menu-item"
                                            // Indented, so the menu still shows
                                            // the shape of the path it stands in
                                            // for rather than a flat list.
                                            style={{ paddingLeft: `${10 + depth * 12}px` }}
                                            onClick={() => {
                                                select(crumb.id);
                                                setMenuOpen(false);
                                            }}
                                        >
                                            {crumb.label}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        ) : null}
                    </li>
                ) : null}

                {shown.map((crumb, i) => (
                    <Item
                        key={String(crumb.id)}
                        crumb={crumb}
                        current={i === shown.length - 1}
                        onSelect={select}
                    />
                ))}
            </ol>
        </nav>
    );
}

function Item({
    crumb,
    current,
    onSelect,
}: {
    crumb: Crumb;
    current: boolean;
    onSelect: (id: number | null) => void;
}) {
    return (
        <li className="folderfolio-crumbs__item">
            {/*
              The separator belongs to the crumb that follows it, so the first
              one has none and the row never starts with a slash.
            */}
            <span className="folderfolio-crumbs__sep" aria-hidden="true">
                /
            </span>

            {current ? (
                // Not a button: pressing it would re-filter to where you
                // already are. aria-current says which one you are on.
                <span className="folderfolio-crumbs__current" aria-current="page">
                    {crumb.label}
                </span>
            ) : (
                <button
                    type="button"
                    className="folderfolio-crumbs__link"
                    onClick={() => onSelect(crumb.id)}
                >
                    {crumb.label}
                </button>
            )}

            {/*
              The way out of the filter, put where the filter is stated.

              The alternative that was weighed and rejected was making the
              selected rail row a toggle — click the folder you are in and go
              back to All media. It reads well in the abstract, because a
              folder here is a filter and not a place, but it collides with
              three things this build already does: the narrow sheet's own
              header *is* a press-to-filter control on the folder you are in,
              so the same gesture would mean two opposite things depending on
              the view; `Enter` on a focused tree row is the key that filters,
              so confirming where you are would throw you out; and a folder row
              invites the double-click everyone learned from Finder, whose
              second click would silently clear.

              None of that applies to a control of its own with a label on it.
              It is separately focusable, it cannot misfire, and it sits in the
              one line on the screen whose whole job is to say what the library
              is filtered to.

              Only on the current crumb, and only when that crumb is a filter:
              All media as the sole crumb is not a state there is anything to
              clear. Unassigned *is* one — it is the absence of a folder, which
              is still a filter over the library — so it gets the control too.
            */}
            {current && crumb.id !== null ? (
                <button
                    type="button"
                    className="folderfolio-crumbs__clear"
                    title={t('clearFolderFilter', 'Clear the folder filter')}
                    aria-label={t('clearFolderFilter', 'Clear the folder filter')}
                    onClick={() => onSelect(null)}
                >
                    <CloseIcon size={14} />
                </button>
            ) : null}
        </li>
    );
}
