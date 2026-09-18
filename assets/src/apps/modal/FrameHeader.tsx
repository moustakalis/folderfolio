/**
 * The frame column's header — screen 10.
 *
 * An eyebrow and one 26 × 26 overflow button, where the rail has an eyebrow
 * and a labelled primary button above a row of four more. That is not the
 * rail's toolbar made smaller: at 240px four labelled buttons would be four
 * truncated words, and the design's answer is to collapse the lot behind one
 * control titled "Folder actions".
 *
 * The menu is flat rather than nested. A submenu inside a popup inside a modal
 * is three layers of dismissal to get wrong, and there are only seven items.
 * Sort sits below a rule with ticks, so the group reads as a set of states
 * rather than as four more actions.
 */

import { useState } from 'react';

import { Menu } from '../rail/Toolbar';
import { EllipsisIcon } from '../rail/icons';
import type { FolderNode } from '../rail/queries';
import { useRail, type SortOrder } from '../rail/store';
import { t } from '../../core/api';

const SORTS: Array<{ value: SortOrder; label: string; fallback: string }> = [
    { value: 'name-asc', label: 'sortNameAsc', fallback: 'Name, A to Z' },
    { value: 'name-desc', label: 'sortNameDesc', fallback: 'Name, Z to A' },
    { value: 'newest', label: 'sortNewest', fallback: 'Newest first' },
    { value: 'oldest', label: 'sortOldest', fallback: 'Oldest first' },
];

export function FrameHeader({
    selected,
    onDelete,
}: {
    selected: FolderNode | null;
    onDelete: () => void;
}) {
    const selectedId = useRail((s) => s.selectedId);
    const expand = useRail((s) => s.expand);
    const edit = useRail((s) => s.edit);
    const sort = useRail((s) => s.sort);
    const setSort = useRail((s) => s.setSort);
    const [open, setOpen] = useState(false);

    function startCreate() {
        const parentId = selectedId !== null && selectedId > 0 ? selectedId : null;

        if (parentId !== null) {
            expand(parentId);
        }

        edit({ mode: 'create', parentId, folderId: null, value: '' });
        setOpen(false);
    }

    return (
        <div className="folderfolio-frame__header">
            {/* The product's name, as in the library rail's header — same
                eyebrow, same role, and the two would look like different
                plugins if only one of them carried it. */}
            <span className="folderfolio-rail__eyebrow">FolderFolio</span>

            <div className="folderfolio-frame__actions">
                <button
                    type="button"
                    className="folderfolio-frame__more"
                    aria-haspopup="menu"
                    aria-expanded={open}
                    aria-label={t('folderActions', 'Folder actions')}
                    title={t('folderActions', 'Folder actions')}
                    onClick={() => setOpen((was) => !was)}
                >
                    <EllipsisIcon size={14} />
                </button>

                {open ? (
                    <Menu onClose={() => setOpen(false)}>
                        <button
                            type="button"
                            role="menuitem"
                            className="folderfolio-menu__item"
                            onClick={startCreate}
                        >
                            <span className="folderfolio-menu__tick" aria-hidden="true" />
                            {t('newFolder', 'New folder')}
                        </button>

                        <button
                            type="button"
                            role="menuitem"
                            className="folderfolio-menu__item"
                            disabled={selected === null}
                            onClick={() => {
                                if (selected) {
                                    edit({
                                        mode: 'rename',
                                        parentId: null,
                                        folderId: selected.id,
                                        value: selected.name,
                                    });
                                }

                                setOpen(false);
                            }}
                        >
                            <span className="folderfolio-menu__tick" aria-hidden="true" />
                            {t('rename', 'Rename')}
                        </button>

                        <button
                            type="button"
                            role="menuitem"
                            className="folderfolio-menu__item"
                            disabled={selected === null}
                            onClick={() => {
                                onDelete();
                                setOpen(false);
                            }}
                        >
                            <span className="folderfolio-menu__tick" aria-hidden="true" />
                            {t('delete', 'Delete')}
                        </button>

                        <div className="folderfolio-menu__rule" role="separator" />

                        {SORTS.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                role="menuitemradio"
                                aria-checked={sort === option.value}
                                className="folderfolio-menu__item"
                                onClick={() => {
                                    setSort(option.value);
                                    setOpen(false);
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
