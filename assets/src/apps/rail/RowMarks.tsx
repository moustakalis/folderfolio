/**
 * A row's pin, star and lock — tier 2 item 10, board 3ZU8VGkJemznTvKp8tNnvY.
 *
 * Beside the count, not after the name (answer 2): the name is the one track
 * that may be clipped, and a mark clipped with it would say nothing. Here the
 * name keeps its left edge and the marks keep their place from row to row.
 *
 * A lock that covers the folder from an ancestor is drawn faded — the same
 * shape, lighter — so "locked because Acme is" reads differently from "Acme".
 * The icons are decorative; the words go to assistive tech in one hidden run,
 * so the treeitem's name reads "Logos, locked" rather than nothing at all.
 *
 * Used by both renderers — `Row` above 782px and `LevelRow` below.
 */

import { LockIcon, PinIcon, StarIcon } from './icons';
import { useRail } from './store';
import { t } from '../../core/api';

/**
 * The same marks as words — ", pinned, locked" — for a row whose accessible
 * name is an aria-label, which replaces the hidden run below rather than
 * adding to it. Empty when the row carries none.
 */
export function useMarkWords(id: number, pinned?: boolean, lockedBy?: number | null): string {
    const starred = useRail((s) => s.stars.includes(id));
    const words = [
        pinned ? t('pinned', 'pinned') : null,
        starred ? t('starred', 'starred') : null,
        lockedBy != null ? t('locked', 'locked') : null,
    ].filter(Boolean);

    return words.length ? `, ${words.join(', ')}` : '';
}

export function RowMarks({
    id,
    pinned,
    lockedBy,
    labelled = false,
}: {
    id: number;
    pinned?: boolean;
    lockedBy?: number | null;
    /** The row names itself with an aria-label that already says the words. */
    labelled?: boolean;
}) {
    // One boolean, like selection and focus: starring a folder re-renders the
    // row that changed and nothing else.
    const starred = useRail((s) => s.stars.includes(id));
    const words = useMarkWords(id, pinned, lockedBy);
    const locked = lockedBy != null;

    if (!pinned && !starred && !locked) {
        return null;
    }

    return (
        <span className="folderfolio-row__marks">
            {pinned ? <PinIcon size={12} filled /> : null}
            {starred ? <StarIcon size={12} filled className="folderfolio-row__star" /> : null}
            {locked ? (
                <LockIcon
                    size={12}
                    className={lockedBy === id ? undefined : 'folderfolio-row__inherited'}
                />
            ) : null}
            {labelled ? null : <span className="screen-reader-text">{words}</span>}
        </span>
    );
}
