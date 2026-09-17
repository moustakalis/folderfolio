/**
 * The rail toolbar — screen 03.
 *
 * Four 34px labelled buttons in the design. Three of them are here: Rename,
 * Delete and Sort. The fourth, More, is the colour picker and whatever else
 * ends up in an overflow menu — both drawn on screen 11, and both arriving
 * with that screen. A button that enables on selection and then does nothing
 * is worse than one that is not there yet.
 *
 * Labelled, not icon-only. "Delete" next to a bin is redundant; a bin on its
 * own next to a pencil is a guess, and this toolbar acts on whatever folder is
 * selected — the one place in the rail where guessing wrong is expensive.
 */

import { useEffect, useRef, useState } from 'react';

import { ArrowUpDownIcon, EllipsisIcon, PencilIcon, TrashIcon } from './icons';
import type { FolderNode } from './queries';
import { useRail, type SortOrder } from './store';
import { t } from '../../core/api';

const SORTS: Array<{ value: SortOrder; label: string; fallback: string }> = [
    { value: 'name-asc', label: 'sortNameAsc', fallback: 'Name, A to Z' },
    { value: 'name-desc', label: 'sortNameDesc', fallback: 'Name, Z to A' },
    { value: 'newest', label: 'sortNewest', fallback: 'Newest first' },
    { value: 'oldest', label: 'sortOldest', fallback: 'Oldest first' },
];

export function Toolbar({ selected, onDelete }: { selected: FolderNode | null; onDelete: () => void }) {
    const edit = useRail((s) => s.edit);
    const sort = useRail((s) => s.sort);
    const setSort = useRail((s) => s.setSort);
    const [sortOpen, setSortOpen] = useState(false);

    // Only a real folder can be renamed or deleted. All media and Unassigned
    // are selections but not folders, which is exactly the case a disabled
    // state is for.
    const actable = selected !== null;

    return (
        <div className="folderfolio-rail__toolbar" role="toolbar" aria-label={t('folderActions', 'Folder actions')}>
            <button
                type="button"
                className="folderfolio-rail__tool"
                disabled={!actable}
                onClick={() =>
                    selected &&
                    edit({ mode: 'rename', parentId: null, folderId: selected.id, value: selected.name })
                }
            >
                <PencilIcon size={14} />
                {t('rename', 'Rename')}
            </button>

            <button
                type="button"
                className="folderfolio-rail__tool"
                disabled={!actable}
                onClick={onDelete}
            >
                <TrashIcon size={14} />
                {t('delete', 'Delete')}
            </button>

            {/* Sort is about the view, not the selection, so it is never disabled. */}
            <div className="folderfolio-rail__tool-wrap">
                <button
                    type="button"
                    className="folderfolio-rail__tool"
                    aria-haspopup="menu"
                    aria-expanded={sortOpen}
                    onClick={() => setSortOpen((open) => !open)}
                >
                    <ArrowUpDownIcon size={14} />
                    {t('sort', 'Sort')}
                </button>

                {sortOpen ? (
                    <Menu onClose={() => setSortOpen(false)}>
                        {SORTS.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                role="menuitemradio"
                                aria-checked={sort === option.value}
                                className="folderfolio-menu__item"
                                onClick={() => {
                                    setSort(option.value);
                                    setSortOpen(false);
                                }}
                            >
                                <span className="folderfolio-menu__tick" aria-hidden="true">
                                    {sort === option.value ? '✓' : ''}
                                </span>
                                {t(option.label, option.fallback)}
                            </button>
                        ))}
                    </Menu>
                ) : null}
            </div>
        </div>
    );
}

/**
 * A small popup menu: closes on Escape, on a click outside, and when focus
 * leaves it. All three, because each covers a case the others do not — Escape
 * for the keyboard, the outside click for the mouse, and focus leaving for Tab.
 */
export function Menu({ children, onClose }: { children: React.ReactNode; onClose: () => void }) {
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        ref.current?.querySelector<HTMLElement>('button')?.focus();

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                onClose();
            }
        };

        const onPointer = (event: PointerEvent) => {
            if (!ref.current?.parentElement?.contains(event.target as Node)) {
                onClose();
            }
        };

        document.addEventListener('keydown', onKey, true);
        document.addEventListener('pointerdown', onPointer, true);

        return () => {
            document.removeEventListener('keydown', onKey, true);
            document.removeEventListener('pointerdown', onPointer, true);
        };
    }, [onClose]);

    return (
        <div
            ref={ref}
            className="folderfolio-menu"
            role="menu"
            onBlur={(event) => {
                if (!event.currentTarget.contains(event.relatedTarget)) {
                    onClose();
                }
            }}
        >
            {children}
        </div>
    );
}
