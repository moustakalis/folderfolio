/**
 * All media and Unassigned — screen 03.
 *
 * Fixed, above the tree and outside it, because neither is a folder. Putting
 * them in the tree would make them arrow-navigable siblings of real folders
 * that cannot be renamed, deleted, dropped into or nested, and every one of
 * those affordances would then have to be special-cased.
 *
 * All media is also the way *out* of a filter. Clicking the selected folder
 * again does nothing in this rail, deliberately: a toggle that clears means
 * the same click does two different things depending on state, and you cannot
 * see which from looking.
 */

import { ImageIcon, InboxIcon } from './icons';
import { useLibraryCounts } from './queries';
import { useRail } from './store';
import { t } from '../../core/api';

export function FixedRows() {
    const selectedId = useRail((s) => s.selectedId);
    const select = useRail((s) => s.select);
    const { data } = useLibraryCounts();

    return (
        <div className="folderfolio-rail__fixed">
            <FixedRow
                label={t('allMedia', 'All media')}
                count={data?.all}
                selected={selectedId === null}
                onSelect={() => select(null)}
                icon={<ImageIcon />}
            />

            {/*
              `0` is a real selection, not an absence: MediaLibraryFilter reads
              it as "media in no folder at all".
            */}
            <FixedRow
                label={t('unassigned', 'Unassigned')}
                count={data?.unassigned}
                selected={selectedId === 0}
                onSelect={() => select(0)}
                icon={<InboxIcon />}
            />
        </div>
    );
}

function FixedRow({
    label,
    count,
    selected,
    onSelect,
    icon,
}: {
    label: string;
    count: number | undefined;
    selected: boolean;
    onSelect: () => void;
    icon: React.ReactNode;
}) {
    return (
        <button
            type="button"
            className="folderfolio-row folderfolio-rail__fixed-row"
            aria-selected={selected}
            aria-label={count === undefined ? label : `${label}, ${count}`}
            onClick={onSelect}
        >
            <span className="folderfolio-row__icon">{icon}</span>
            <span className="folderfolio-row__name">{label}</span>

            {/*
              Nothing rather than a zero while the count is still in flight. A
              placeholder 0 on All media is a specific lie, and it is the exact
              lie this plugin exists to stop telling.
            */}
            <span className="folderfolio-row__count">{count ?? ''}</span>
        </button>
    );
}
