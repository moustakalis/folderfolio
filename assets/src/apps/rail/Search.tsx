/**
 * Folder search — screen 03.
 *
 * A control of its own, separate from create. v0.2.0's rail had one visible
 * text field and it was ambiguous which it was; FileBird has the same problem
 * from the other direction, with an always-visible field that looks like a
 * create box and is in fact a search box.
 *
 * Typing replaces the tree with a flat result list rather than filtering rows
 * in place. Filtering in place hides a matching child inside a collapsed
 * parent — the parent does not match, so it disappears, and the child goes
 * with it. A flat list with each result's full path underneath cannot have
 * that bug, and it also answers the question a search is usually asking:
 * *where* is this folder.
 */

import { useEffect, useRef } from 'react';

import { SearchIcon } from './icons';
import { useRail } from './store';
import { useIsNarrow } from '../../lib/narrow';
import { t } from '../../core/api';

/** The class rail.ts puts on <body> while the narrow sheet is open. */
const PEEK = 'folderfolio-rail-peek';

export function Search() {
    const query = useRail((s) => s.query);
    const setQuery = useRail((s) => s.setQuery);
    const narrow = useIsNarrow();
    const ref = useRef<HTMLInputElement>(null);

    /*
     * On a phone, opening the sheet puts the cursor here.
     *
     * The fixture says why. A level of the drill-down view averages 3.7 rows
     * and 72% of them fit the window whole — but the level you land on is the
     * top one, and that is 47 roots in the fixture and a dozen or more in a
     * real library. Typing four characters reaches any of 1,053 folders; the
     * alternative is scrolling a list the window shows four rows of.
     *
     * Only when narrow, and only as the sheet opens. Focusing this on a
     * desktop would take the cursor away from whatever the person was doing
     * every time the rail rendered, and the desktop tree does not have the
     * problem this solves.
     *
     * A class observer rather than a prop: the open state belongs to rail.ts,
     * which is server-rendered chrome outside this React tree — it has to work
     * before this bundle loads and if it never does.
     */
    useEffect(() => {
        if (!narrow) {
            return;
        }

        let was = document.body.classList.contains(PEEK);

        const check = () => {
            const now = document.body.classList.contains(PEEK);

            // The transition, not the state: re-focusing on every unrelated
            // class change would fight anyone who had moved on to a row.
            if (now && !was) {
                ref.current?.focus();
            }

            was = now;
        };

        const observer = new MutationObserver(check);
        observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });

        return () => observer.disconnect();
    }, [narrow]);

    return (
        <div className="folderfolio-rail__search">
            <span className="folderfolio-rail__search-icon">
                <SearchIcon size={14} />
            </span>

            <input
                ref={ref}
                type="search"
                className="folderfolio-rail__search-input"
                value={query}
                placeholder={t('searchPlaceholder', 'Search folders')}
                aria-label={t('searchPlaceholder', 'Search folders')}
                onChange={(event) => setQuery(event.target.value)}
                onKeyDown={(event) => {
                    // Escape clears the search before it does anything else —
                    // the innermost thing Escape could mean here.
                    if (event.key === 'Escape' && query !== '') {
                        event.preventDefault();
                        event.stopPropagation();
                        setQuery('');
                    }
                }}
            />
        </div>
    );
}
