/**
 * Everything you can do to one folder — screen 11, §9.8, grown.
 *
 * ## One menu, two doors
 *
 * Above 782px it opens from a ⋮ on the selected row; below, from the
 * toolbar's More. Same component, same contents, same gates — the only
 * difference is what it is pinned to. That is the whole point of the split
 * below: `FolderMenuItems` is the menu, and the two exported wrappers are
 * only containers.
 *
 * The rule the shape comes from is Nick's: **no action in two places at one
 * width**. So Rename and Delete left the toolbar entirely and live here, and
 * the toolbar keeps only what is not a folder action — the global sort, which
 * needs no selection and no permission and therefore cannot live in a menu
 * that has both.
 *
 * ## No heading
 *
 * It said "Colour for <name>" when colour was all it did, then the name alone
 * when it grew. Both are gone: opened from the row, the menu is physically on
 * the folder it acts on, and opened from More it acts on the selection, which
 * is the row drawn in the accent colour two pixels away. A title that repeats
 * what the pointer is already touching is a row of chrome.
 *
 * ## Colour, unchanged
 *
 * Ten fixed swatches and "No colour". No free picker and no hex field: eight
 * colour schemes ship with core, and a hex chosen while looking at one of
 * them cannot be guaranteed to stay legible on the other seven. What is
 * stored is a swatch *name*, and `--ff-folder-<name>` is what resolves it per
 * scheme — see lib/swatches.ts.
 */

import { createPortal } from 'react-dom';

import { ArrowDownIcon, ArrowUpIcon, PencilIcon, TrashIcon } from './icons';
import { planSiblingMove } from './move';
import { Menu } from './Menu';
import type { FolderNode } from './queries';
import { useReorderFolders, useSetFolderColor } from './queries';
import { useRail } from './store';
import { useAnchoredPanel } from './useAnchoredPanel';
import { can } from '../../lib/can';
import { SWATCHES, isSwatch, swatchLabel, type Swatch } from '../../lib/swatches';
import { t } from '../../core/api';

interface FolderMenuProps {
    folder: FolderNode;
    /** The tree as the person is looking at it — see move.ts on why sorted. */
    ordered: FolderNode[];
    onDelete: () => void;
    onClose: () => void;
}

/**
 * The items. Rendered inside whichever container opened them.
 *
 * Every one of them closes the menu after acting. A menu that stays open
 * after a destructive or navigational command is a menu you have to dismiss
 * twice, and the one case for staying open — picking several colours in a
 * row — is not a thing anybody does.
 */
function FolderMenuItems({ folder, ordered, onDelete, onClose }: FolderMenuProps) {
    const setColor = useSetFolderColor();
    const reorder = useReorderFolders();
    const setSort = useRail((s) => s.setSort);
    const edit = useRail((s) => s.edit);
    const levelId = useRail((s) => s.levelId);
    const openLevel = useRail((s) => s.openLevel);
    const current = isSwatch(folder.color) ? folder.color : null;

    // Asked here rather than inside the click, so an item that is enabled and
    // an action that does nothing cannot disagree. A folder alone on its
    // level has both disabled, which is the honest answer to "can I move
    // this" and cheaper than a toast saying no afterwards.
    const up = planSiblingMove(ordered, folder.id, -1);
    const down = planSiblingMove(ordered, folder.id, 1);

    function choose(color: Swatch | null) {
        setColor.mutate({ id: folder.id, color });
        onClose();
    }

    function move(plan: ReturnType<typeof planSiblingMove>) {
        if (!plan) {
            return;
        }

        // Step out first, when the folder being moved is the level we are
        // standing in.
        //
        // In Levels.tsx tapping a folder that has children both selects it
        // and walks into it, so the selection can be the header rather than a
        // row — and its siblings, the only place the move is visible, are one
        // level back. Without this the person taps Move down, the endpoint
        // succeeds, and nothing on screen changes. `levelId` is null in the
        // wide tree, so this never fires there.
        if (levelId !== null && levelId === folder.id) {
            openLevel(plan.parentId);
        }

        // Same as a drop and as Alt+Arrow: the arrangement is only visible
        // under Custom, and the person has just made one.
        setSort('custom');
        reorder.mutate(plan);
        onClose();
    }

    const organise = can('rename');

    return (
        <>
            {organise ? (
                <>
                    <button
                        type="button"
                        role="menuitem"
                        className="folderfolio-menu__item"
                        onClick={() => {
                            edit({
                                mode: 'rename',
                                parentId: null,
                                folderId: folder.id,
                                value: folder.name,
                            });
                            onClose();
                        }}
                    >
                        <PencilIcon size={13} />
                        {t('rename', 'Rename')}
                    </button>

                    <button
                        type="button"
                        role="menuitem"
                        className="folderfolio-menu__item"
                        disabled={up === null}
                        onClick={() => move(up)}
                    >
                        <ArrowUpIcon size={13} />
                        {t('moveUp', 'Move up')}
                    </button>

                    <button
                        type="button"
                        role="menuitem"
                        className="folderfolio-menu__item"
                        disabled={down === null}
                        onClick={() => move(down)}
                    >
                        <ArrowDownIcon size={13} />
                        {t('moveDown', 'Move down')}
                    </button>

                    <div className="folderfolio-menu__rule" role="separator" />

                    {/*
                      role="group" inside the menu, so a screen reader
                      announces the eleven choices as one set with one of them
                      checked, rather than as eleven unrelated menu items. The
                      eleventh is "No colour", which is a value in this set and
                      not an escape from it — which is why it is a radio and
                      not a separate command.
                    */}
                    <div
                        className="folderfolio-swatches"
                        role="group"
                        aria-label={t('folderColor', 'Folder colour')}
                    >
                        {SWATCHES.map((swatch) => (
                            <button
                                key={swatch}
                                type="button"
                                role="menuitemradio"
                                aria-checked={current === swatch}
                                aria-label={swatchLabel(swatch)}
                                title={swatchLabel(swatch)}
                                className="folderfolio-swatches__swatch"
                                style={{ background: `var(--ff-folder-${swatch})` }}
                                onClick={() => choose(swatch)}
                            />
                        ))}
                    </div>

                    <button
                        type="button"
                        role="menuitemradio"
                        aria-checked={current === null}
                        className="folderfolio-swatches__none"
                        onClick={() => choose(null)}
                    >
                        <span className="folderfolio-swatches__empty" aria-hidden="true" />
                        {t('noColor', 'No colour')}
                    </button>
                </>
            ) : null}

            {/*
              Delete last and behind its own rule, and gated on `delete`
              rather than on `rename` — the two abilities are separate columns
              in the roles matrix and a role can hold either one alone. A
              reader with neither never opens this menu: the ⋮ is not drawn.
            */}
            {can('delete') ? (
                <>
                    {organise ? <div className="folderfolio-menu__rule" role="separator" /> : null}

                    <button
                        type="button"
                        role="menuitem"
                        className="folderfolio-menu__item folderfolio-menu__item--danger"
                        onClick={() => {
                            onDelete();
                            onClose();
                        }}
                    >
                        <TrashIcon size={13} />
                        {t('delete', 'Delete')}
                    </button>
                </>
            ) : null}
        </>
    );
}

/**
 * The toolbar's door — below 782px, where there is no ⋮.
 *
 * Absolutely positioned inside the tool's wrapper and right-aligned, because
 * More is the last tool in a 300px rail and a 240px panel opening from its
 * left edge would hang off the rail entirely. `Menu` supplies Escape, the
 * outside click, and focus returning to the button.
 */
export function FolderMenu(props: FolderMenuProps) {
    return (
        <Menu className="folderfolio-menu--folder" onClose={props.onClose}>
            <FolderMenuItems {...props} />
        </Menu>
    );
}

/**
 * The row's door — above 782px.
 *
 * Portaled to the body rather than rendered in the row, for the reason
 * AddToFolder's flyout is: the tree is a scroller with its own overflow, and
 * a panel inside it is clipped by the row two below the one that opened it.
 * `useAnchoredPanel` pins it, clamps it to the window, flips it above the
 * trigger when there is no room underneath, follows a scroll, and closes on
 * Escape or a pointer outside — all of it already written for the two flyouts
 * that use it.
 */
export function RowMenu({
    anchor,
    ...props
}: FolderMenuProps & { anchor: HTMLElement | null }) {
    const { ref, style } = useAnchoredPanel<HTMLDivElement>(anchor, props.onClose);

    return createPortal(
        <div
            ref={ref}
            style={style}
            className="folderfolio folderfolio-menu folderfolio-menu--row"
            role="menu"
            aria-label={props.folder.name}
        >
            <FolderMenuItems {...props} />
        </div>,
        document.body
    );
}
