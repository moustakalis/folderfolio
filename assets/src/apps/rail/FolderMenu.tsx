/**
 * The panel behind More — screen 11, §9.8, plus what overflowed into it.
 *
 * Move up, Move down, and then the colour picker: ten fixed swatches and "No
 * colour" in a 240px panel under the toolbar's fourth button. No free picker
 * and no hex field: eight colour schemes ship with core, and a hex chosen
 * while looking at one of them cannot be guaranteed to stay legible on the
 * other seven. What is stored is a swatch *name*, and `--ff-folder-<name>` is
 * what resolves it per scheme — see lib/swatches.ts.
 *
 * This file used to argue that the picker was the whole of More, on the
 * grounds that an overflow menu is for what is overflowing and every folder
 * action already had a home. Reordering is what overflowed. It arrived with
 * two gestures — a drag and Alt+Arrow — and both of them live in Tree.tsx,
 * which is not drawn below 782px. Levels.tsx has no drop target and no key
 * handler, so on a narrow window and on every phone the feature did not
 * exist. The toolbar is rendered above the renderer switch in Rail.tsx, so
 * two items here reach both renderers, touch included, and give the wide tree
 * a visible form of a modifier shortcut nobody discovers.
 *
 * Right-aligned, unlike the Sort menu: More is the last of four buttons in a
 * 300px rail, and a 240px panel opening from its left edge would hang off the
 * rail entirely.
 */

import { ArrowDownIcon, ArrowUpIcon } from './icons';
import { planSiblingMove } from './move';
import { Menu } from './Toolbar';
import type { FolderNode } from './queries';
import { useReorderFolders, useSetFolderColor } from './queries';
import { useRail } from './store';
import { SWATCHES, isSwatch, swatchLabel, type Swatch } from '../../lib/swatches';
import { t } from '../../core/api';

export function FolderMenu({
    folder,
    ordered,
    onClose,
}: {
    folder: FolderNode;
    /** The tree as the person is looking at it — see move.ts on why sorted. */
    ordered: FolderNode[];
    onClose: () => void;
}) {
    const setColor = useSetFolderColor();
    const reorder = useReorderFolders();
    const setSort = useRail((s) => s.setSort);
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

    return (
        <Menu className="folderfolio-menu--folder" onClose={onClose}>
            {/*
              The folder's name, and not "Colour for %s" as this panel read
              when colour was all it did. The menu acts on the selection,
              which in the narrow renderer is a row you tapped and then
              navigated away from — naming it is the difference between three
              items and three items aimed at something.
            */}
            <p className="folderfolio-menu__head">
                <strong>{folder.name}</strong>
            </p>

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
              role="group" inside the menu, so a screen reader announces the
              eleven choices as one set with one of them checked, rather than
              as eleven unrelated menu items. The eleventh is "No colour",
              which is a value in this set and not an escape from it — which
              is why it is a radio and not a separate command.
            */}
            <div className="folderfolio-swatches" role="group" aria-label={t('folderColor', 'Folder colour')}>
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
        </Menu>
    );
}
