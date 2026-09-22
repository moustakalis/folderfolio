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

import { useLayoutEffect, useRef, useState, type ReactNode } from 'react';

import { CloseIcon } from './icons';
import { useRail } from './store';
import { t } from '../../core/api';

export interface Crumb {
    /** `null` is All media — the root of every path. */
    id: number | null;
    label: string;
}

/**
 * The way out of a filter, and anything else that belongs beside it.
 *
 * Two scales, because there are two rows. The library's is 908px and gets
 * labelled buttons — Nick's call, and the × it replaces was an 18px glyph
 * with no name on it that shared the path's clipping box. The media picker's
 * is **220px**, wide enough that the same label would take a third of it, so
 * it keeps the icon. One action, drawn at the size its row can afford, the
 * same way `_frame.css` already gives that column a 28px search instead of
 * the rail's 30px.
 *
 * The caller says which, rather than a flag being read in here, because the
 * library also passes a second control and the picker does not.
 */
export function ClearFilter({ compact = false }: { compact?: boolean }) {
    const select = useRail((s) => s.select);

    if (compact) {
        return (
            <button
                type="button"
                className="folderfolio-crumbs__clear"
                title={t('clearFilter', 'Clear filter')}
                aria-label={t('clearFilter', 'Clear filter')}
                onClick={() => select(null)}
            >
                <CloseIcon size={14} />
            </button>
        );
    }

    return (
        <button
            type="button"
            className="folderfolio-crumbs__control"
            onClick={() => select(null)}
        >
            {t('clearFilter', 'Clear filter')}
        </button>
    );
}

/**
 * @param controls What goes at the end of the row when a folder is being
 *                 filtered to. Drawn only then — All media as the sole crumb
 *                 is not a state there is anything to clear or to start in.
 */
export function Breadcrumb({ crumbs, controls }: { crumbs: Crumb[]; controls?: ReactNode }) {
    const select = useRail((s) => s.select);
    const selectedId = useRail((s) => s.selectedId);
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

    /*
     * The controls are drawn when a folder is being filtered to, which is the
     * same rule the × followed: All media as the sole crumb is not a state
     * there is anything to clear or to start in. Unassigned IS one — it is
     * the absence of a folder, which is still a filter over the library, and
     * "show me what is not filed yet" is a startup folder somebody wants.
     */
    const filtering = selectedId !== null;

    return (
        <nav className="folderfolio-crumbs" aria-label={t('breadcrumb', 'Folder path')} ref={ref}>
            {/*
              Two tracks, and the split is the whole point of this row's
              shape: the path may be clipped, the controls never are.

              The × used to sit inside the list, after the current crumb — so
              it shared the path's clipping box and could be scrolled out of
              existence by a deep enough path, and it was an 18px glyph with
              no name on it. Nick's call: labelled buttons, at the end of the
              row. `min-width: 0` on the path is what lets it shrink at all;
              without it a grid track never goes below its content and the
              controls would be pushed out of the box instead.
            */}
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

            {filtering && controls ? (
                <div className="folderfolio-crumbs__controls">{controls}</div>
            ) : null}
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

        </li>
    );
}
