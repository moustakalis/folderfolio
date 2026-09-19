/**
 * Is the rail in its narrow form?
 *
 * ## Why this one is a media query, when the toolbar's is not
 *
 * The toolbar's breakpoint belongs to a container query and is deliberately
 * unreadable from script — the width that decides it is the library column's,
 * which depends on a rail the user drags to whatever width they like. See
 * FilterDisclosure.tsx.
 *
 * The rail's breakpoint is the opposite case. Below 782px the rail is not a
 * column beside the library at all: Rail.php's inline script moves it out from
 * beside `#wpbody-content` and into the page, under the title, as a full-width
 * band. That move is already decided by `matchMedia('(max-width: 782px)')` —
 * the same number wp-admin itself uses to fold the admin menu — so the
 * question "is the rail a band or a column" has a script-readable answer, and
 * this is it.
 *
 * 782 rather than 783: wp-admin's own rule is `max-width: 782px`, and a
 * component that disagreed with it by one pixel would change shape one pixel
 * away from where everything around it does.
 */

import { useEffect, useState } from 'react';

/** The same query Rail.php's placement script and `_rail.css` both use. */
export const NARROW = '(max-width: 782px)';

export function useIsNarrow(): boolean {
    const [narrow, setNarrow] = useState(() => window.matchMedia(NARROW).matches);

    useEffect(() => {
        const query = window.matchMedia(NARROW);
        const update = () => setNarrow(query.matches);

        // Read once more on mount: between the initial state above and this
        // effect, an orientation change or a devtools resize may have landed.
        update();
        query.addEventListener('change', update);

        return () => query.removeEventListener('change', update);
    }, []);

    return narrow;
}
