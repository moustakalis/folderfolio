/**
 * Below the narrow breakpoint, the library's filters collapse behind one button.
 *
 * ## Why
 *
 * Measured on the development site, grid mode, at a 308px rail: the filter
 * panel is 222px tall at 1100px, 248px at 636px and **296px at 400px** — it
 * gets taller as the window gets narrower, because every control that stops
 * fitting claims a whole row of its own. Three of the four rows at 636px held
 * exactly one control, with 415–499px of empty space beside it.
 *
 * Collapsing media type, date and folder behind one `Filter` button is the
 * only one of the three candidate layouts that *shrinks* the narrow toolbar
 * rather than rearranging it. The other two were measured and drawn first.
 *
 * ## Why the button is always rendered
 *
 * The width that decides this is the width of the **library column** — the
 * viewport minus the admin menu minus the rail, and the rail is dragged to
 * whatever width its owner likes. That is a container query's business, not a
 * media query's, and the same reasoning already governs the search break in
 * `_toolbar.css`.
 *
 * A container query cannot be read from script, so this component does not try
 * to know the width. It renders the button unconditionally and CSS decides
 * whether it is on screen — `display: none` above the breakpoint, which takes
 * it out of the accessibility tree too, so an `aria-expanded` that means
 * nothing up there is never announced.
 *
 * ## Why the open state is a body class
 *
 * The same reason `folderfolio-rail-peek` is: it is a state of this screen at
 * this size, not a preference. Nothing here writes it anywhere, so a filter
 * panel opened on a phone does not follow its owner back to a desktop. And
 * because the rules that honour it live *inside* the container query, the
 * class is inert at every width where the filters are shown anyway — widening
 * the window needs no cleanup.
 */

import { useCallback, useEffect, useState } from 'react';

import { FilterIcon } from './icons';
import { t } from '../../core/api';

/** The class the collapse rules key on. On <body>, like the rail's peek. */
const OPEN = 'folderfolio-filters-open';

/**
 * Every filter control the disclosure speaks for, in both modes.
 *
 * Grid prints `select.attachment-filters` twice — media type and date. List
 * prints `#attachment-filter` and `#filter-by-date` instead, plus the folder
 * select PHP renders. Scoped to `#wpbody-content` so a media modal's toolbar is
 * never counted: it is a different shape in a much narrower frame and has no
 * disclosure of its own.
 */
const FILTERS = [
    '#wpbody-content .media-toolbar-secondary select.attachment-filters',
    '#wpbody-content .wp-filter #attachment-filter',
    '#wpbody-content .wp-filter #filter-by-date',
    '#wpbody-content #folderfolio-folder-filter',
].join(', ');

/**
 * How many of them are set to something other than their first option.
 *
 * `selectedIndex > 0` rather than a list of default values: core's first option
 * is "All media items" or "All dates", ours is "All media", and every one of
 * them is the "no filter" case by construction. A value test would need this
 * file to know each control's sentinel — 0, '' and -1 are all in use — and
 * would be wrong the first time core changed one.
 */
function countActive(): number {
    return Array.from(document.querySelectorAll<HTMLSelectElement>(FILTERS)).filter(
        (select) => select.selectedIndex > 0
    ).length;
}

export function FilterDisclosure() {
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);

    // The class is the whole mechanism, so it is owned here and removed on
    // unmount — otherwise a frame that tears this component down leaves the
    // filters open with nothing left on screen to close them.
    useEffect(() => {
        document.body.classList.toggle(OPEN, open);

        return () => document.body.classList.remove(OPEN);
    }, [open]);

    /*
     * The badge follows the controls, not our own state.
     *
     * A folder can be chosen from the rail, from the cards, or from a deep URL
     * on a cold load, and list mode replaces its whole filter row from the
     * server on every change. So the count is recomputed on any `change` in the
     * document rather than tracked, and once on mount for the URL-on-load case.
     */
    useEffect(() => {
        const recount = () => setActive(countActive());

        recount();
        document.addEventListener('change', recount, true);

        return () => document.removeEventListener('change', recount, true);
    }, []);

    const toggle = useCallback(() => setOpen((was) => !was), []);

    return (
        <button
            type="button"
            className="button folderfolio-filter-toggle"
            aria-expanded={open}
            onClick={toggle}
        >
            <FilterIcon size={14} />
            <span className="folderfolio-filter-toggle__label">{t('filters', 'Filter')}</span>
            {active > 0 ? (
                <span className="folderfolio-filter-toggle__count" aria-hidden="true">
                    {active}
                </span>
            ) : null}
            {active > 0 ? (
                <span className="screen-reader-text">
                    {t('filtersActive', '%s filters active', String(active))}
                </span>
            ) : null}
        </button>
    );
}
