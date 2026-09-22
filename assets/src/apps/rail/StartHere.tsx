/**
 * "Start here" — this user's own startup folder, on the breadcrumb row.
 *
 * Tier 1 item 7b. The site setting under **Opens in** answers the question
 * for everybody; this answers it for one person, and theirs wins.
 *
 * ## Why it is here and not in the folder's ⋮ menu
 *
 * The menu was the obvious home and it fails on permission. `FolderMenu`
 * opens only for `rename` **or** `delete`, and on a default site Author holds
 * `create + assign` and Contributor holds `assign` — so neither would ever
 * see a preference that is purely their own, with no greyed control to
 * explain the absence. Opening the menu for everyone to carry one personal
 * item would in turn cost the ⋮ its meaning: having one currently says *you
 * can change this folder*, and the 40px reserve would appear on every row for
 * read-only users too.
 *
 * The breadcrumb needs no ability, is drawn at every width, and is already
 * the one line whose job is to say what the library is filtered to. This sits
 * next to the control that undoes it.
 *
 * ## A toggle keeps one name
 *
 * The label does not become "Starts here" when pressed. A toggle button has
 * one accessible name and announces its state separately; renaming it on
 * press means a screen reader announces a different control than the one just
 * activated. The fill, the tick and `aria-pressed` carry the state.
 */

import { useState } from 'react';

import { CheckIcon } from './icons';
import { useRail } from './store';
import { apiFetch, t } from '../../core/api';

export function StartHere({ folderId }: { folderId: number }) {
    const startupFolderId = useRail((s) => s.startupFolderId);
    const setStartupFolder = useRail((s) => s.setStartupFolder);
    const [saving, setSaving] = useState(false);

    const pressed = startupFolderId === folderId;

    async function toggle() {
        const next = pressed ? null : folderId;
        const previous = startupFolderId;

        // Optimistic, like every other write in the rail: the press is the
        // answer, and the round trip is bookkeeping.
        setStartupFolder(next);
        setSaving(true);

        try {
            await apiFetch('/preferences', {
                method: 'POST',
                // Only this key. The controller merges onto what is stored,
                // so the rail's width and open state are not restated here
                // and cannot be clobbered by a stale copy of them.
                data: { rail: { startup: next } },
            });
        } catch {
            // Put it back. Unlike the rail's width — where a failed save
            // costs nothing, because the rail is already the size it was
            // dragged to — this one has no other evidence on screen, so a
            // button left looking pressed would be a straight lie about
            // where the library will open tomorrow.
            setStartupFolder(previous);
        } finally {
            setSaving(false);
        }
    }

    return (
        <button
            type="button"
            className={
                'folderfolio-crumbs__control'
                + (pressed ? ' folderfolio-crumbs__control--on' : '')
            }
            aria-pressed={pressed}
            disabled={saving}
            /*
              A tooltip, not the accessible name. The name has to contain the
              visible text or voice control breaks — somebody saying "click
              Start here" would find nothing — so the long form explains and
              the short form names.
            */
            title={t('startHereHint', 'Open the media library in this folder')}
            onClick={() => void toggle()}
        >
            {pressed ? <CheckIcon size={11} /> : null}
            {t('startHere', 'Start here')}
        </button>
    );
}
