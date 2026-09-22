/**
 * The rail toolbar — screen 03.
 *
 * Four 34px labelled buttons: Rename, Delete, Sort and More. More is the
 * colour picker from screen 11 — see ColorPicker, which is all of it, and why
 * nothing else was invented to keep it company.
 *
 * Labelled, not icon-only. "Delete" next to a bin is redundant; a bin on its
 * own next to a pencil is a guess, and this toolbar acts on whatever folder is
 * selected — the one place in the rail where guessing wrong is expensive.
 */

import { useEffect, useRef, useState } from 'react';

import { ColorPicker } from './ColorPicker';
import { ArrowUpDownIcon, EllipsisIcon, PencilIcon, TrashIcon } from './icons';
import type { FolderNode } from './queries';
import { useRail, type SortOrder } from './store';
import { can } from '../../lib/can';
import { t } from '../../core/api';

const SORTS: Array<{ value: SortOrder; label: string; fallback: string }> = [
    { value: 'name-asc', label: 'sortNameAsc', fallback: 'Name, A to Z' },
    { value: 'name-desc', label: 'sortNameDesc', fallback: 'Name, Z to A' },
    { value: 'newest', label: 'sortNewest', fallback: 'Newest first' },
    { value: 'oldest', label: 'sortOldest', fallback: 'Oldest first' },
    // Last, and after a rule in the menu: the four above are views the tree
    // is put into, this one is the tree's own arrangement being shown.
    { value: 'custom', label: 'sortCustom', fallback: 'Custom order' },
];

export function Toolbar({ selected, onDelete }: { selected: FolderNode | null; onDelete: () => void }) {
    const edit = useRail((s) => s.edit);
    const sort = useRail((s) => s.sort);
    const setSort = useRail((s) => s.setSort);
    const [sortOpen, setSortOpen] = useState(false);
    const [moreOpen, setMoreOpen] = useState(false);

    // Only a real folder can be renamed or deleted. All media and Unassigned
    // are selections but not folders, which is exactly the case a disabled
    // state is for.
    //
    // Permission is the second half of the same question. A role without
    // `delete` gets the button disabled rather than a 403 from a route it was
    // never allowed to call.
    const actable = selected !== null;

    return (
        <div className="folderfolio-rail__toolbar" role="toolbar" aria-label={t('folderActions', 'Folder actions')}>
            <button
                type="button"
                className="folderfolio-rail__tool"
                disabled={!actable || !can('rename')}
                title={t('rename', 'Rename')}
                onClick={() =>
                    selected &&
                    edit({ mode: 'rename', parentId: null, folderId: selected.id, value: selected.name })
                }
            >
                <PencilIcon size={14} />
                <span className="folderfolio-rail__tool-label">{t('rename', 'Rename')}</span>
            </button>

            <button
                type="button"
                className="folderfolio-rail__tool"
                disabled={!actable || !can('delete')}
                title={t('delete', 'Delete')}
                onClick={onDelete}
            >
                <TrashIcon size={14} />
                <span className="folderfolio-rail__tool-label">{t('delete', 'Delete')}</span>
            </button>

            {/* Sort is about the view, not the selection, so it is never disabled. */}
            <div className="folderfolio-rail__tool-wrap">
                <button
                    type="button"
                    className="folderfolio-rail__tool"
                    aria-haspopup="menu"
                    aria-expanded={sortOpen}
                    title={t('sort', 'Sort')}
                    onClick={() => setSortOpen((open) => !open)}
                >
                    <ArrowUpDownIcon size={14} />
                    <span className="folderfolio-rail__tool-label">{t('sort', 'Sort')}</span>
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

            {/*
              More acts on the selection, so it is disabled with Rename and
              Delete rather than always live like Sort. The ability is
              `rename` and not one of its own: setting a colour is a POST to
              /folders/{id}, the same route and the same permission check a
              rename goes through, and a second name for it in the matrix
              would be a second name for one answer.
            */}
            <div className="folderfolio-rail__tool-wrap">
                <button
                    type="button"
                    className="folderfolio-rail__tool"
                    disabled={!actable || !can('rename')}
                    aria-haspopup="menu"
                    aria-expanded={moreOpen}
                    title={t('more', 'More')}
                    onClick={() => setMoreOpen((open) => !open)}
                >
                    <EllipsisIcon size={14} />
                    <span className="folderfolio-rail__tool-label">{t('more', 'More')}</span>
                </button>

                {moreOpen && selected ? (
                    <ColorPicker folder={selected} onClose={() => setMoreOpen(false)} />
                ) : null}
            </div>
        </div>
    );
}

/**
 * A small popup menu: closes on Escape, on a click outside, and when focus
 * leaves it. All three, because each covers a case the others do not — Escape
 * for the keyboard, the outside click for the mouse, and focus leaving for Tab.
 *
 * Focus goes into the menu when it opens, and Escape puts it back on the
 * button that opened it. The second half was missing until the colour picker
 * was built: Escape closed the Sort menu and left focus on <body>, so the
 * next Tab started again from the top of wp-admin — a keyboard user who
 * glanced at a menu and changed their mind lost their place on the page. It
 * is fixed here rather than in the picker because both menus are this
 * component.
 *
 * Escape only, and decided at the moment of closing rather than read from
 * document.activeElement in the cleanup: by the time a passive effect's
 * cleanup runs React has already removed the menu, so focus is on <body> and
 * "was it still in the menu" can no longer be asked. The other two closes
 * must not restore anyway — a click outside and a Tab away are both the user
 * putting focus somewhere deliberately, and pulling it back to the button
 * would be the menu arguing with them.
 */
export function Menu({
    children,
    onClose,
    className,
}: {
    children: React.ReactNode;
    onClose: () => void;
    className?: string;
}) {
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const opener = document.activeElement as HTMLElement | null;
        let dismissed = false;

        ref.current?.querySelector<HTMLElement>('button')?.focus();

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                dismissed = true;
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

            if (dismissed && opener?.isConnected) {
                opener.focus();
            }
        };
    }, [onClose]);

    return (
        <div
            ref={ref}
            className={`folderfolio-menu${className ? ` ${className}` : ''}`}
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
