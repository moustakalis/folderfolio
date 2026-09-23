/**
 * A row's gallery, pin, star and lock — tier 2 item 10, board
 * 3ZU8VGkJemznTvKp8tNnvY; the gallery mark tier 3 item 14.
 *
 * The gallery is first: it is what the folder is, where the other three are
 * how it is kept. A picture glyph rather than the board's word tag — the tag
 * is ~50px in a name track that is 111px at depth 3, and the marks were
 * placed beside the count precisely so that nothing takes the name's room.
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

import { ImageIcon, LockIcon, PinIcon, StarIcon } from './icons';
import { useRail } from './store';
import { t } from '../../core/api';

/**
 * The same marks as words — ", pinned, locked" — for a row whose accessible
 * name is an aria-label, which replaces the hidden run below rather than
 * adding to it. Empty when the row carries none.
 */
export function useMarkWords(id: number, pinned?: boolean, lockedBy?: number | null, gallery?: boolean): string {
    const starred = useRail((s) => s.stars.includes(id));
    const words = [
        gallery ? t('galleryWord', 'gallery') : null,
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
    gallery = false,
    labelled = false,
}: {
    id: number;
    pinned?: boolean;
    lockedBy?: number | null;
    gallery?: boolean;
    /** The row names itself with an aria-label that already says the words. */
    labelled?: boolean;
}) {
    // One boolean, like selection and focus: starring a folder re-renders the
    // row that changed and nothing else.
    const starred = useRail((s) => s.stars.includes(id));
    const words = useMarkWords(id, pinned, lockedBy, gallery);
    const locked = lockedBy != null;

    if (!pinned && !starred && !locked && !gallery) {
        return null;
    }

    return (
        <span className="folderfolio-row__marks">
            {gallery ? <ImageIcon size={12} className="folderfolio-row__gallery" /> : null}
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
