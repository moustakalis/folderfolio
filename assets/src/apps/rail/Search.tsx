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

import { SearchIcon } from './icons';
import { useRail } from './store';
import { t } from '../../core/api';

export function Search() {
    const query = useRail((s) => s.query);
    const setQuery = useRail((s) => s.setQuery);

    return (
        <div className="folderfolio-rail__search">
            <span className="folderfolio-rail__search-icon">
                <SearchIcon size={14} />
            </span>

            <input
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
