/**
 * The colour picker behind More — screen 11, §9.8.
 *
 * Ten fixed swatches and "No colour", in a 240px panel under the toolbar's
 * fourth button. No free picker and no hex field: eight colour schemes ship
 * with core, and a hex chosen while looking at one of them cannot be
 * guaranteed to stay legible on the other seven. What is stored is a swatch
 * *name*, and `--ff-folder-<name>` is what resolves it per scheme — see
 * lib/swatches.ts.
 *
 * The panel is the whole of More. The board draws nothing else inside it, and
 * every other folder action already has a home — New folder in the header,
 * Rename and Delete in this toolbar, both also on F2 and Delete in the tree.
 * An overflow menu is for what is overflowing; inventing items to fill one
 * would be adding features to a release candidate.
 *
 * Right-aligned, unlike the Sort menu: More is the last of four buttons in a
 * 300px rail, and a 240px panel opening from its left edge would hang off the
 * rail entirely.
 */

import { Menu } from './Toolbar';
import type { FolderNode } from './queries';
import { useSetFolderColor } from './queries';
import { SWATCHES, isSwatch, swatchLabel, type Swatch } from '../../lib/swatches';
import { t } from '../../core/api';

export function ColorPicker({ folder, onClose }: { folder: FolderNode; onClose: () => void }) {
    const setColor = useSetFolderColor();
    const current = isSwatch(folder.color) ? folder.color : null;

    function choose(color: Swatch | null) {
        setColor.mutate({ id: folder.id, color });
        onClose();
    }

    return (
        <Menu className="folderfolio-menu--swatches" onClose={onClose}>
            <p className="folderfolio-swatches__head">{heading(folder.name)}</p>

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

/**
 * "Colour for **Campaigns**", with the name bold as the board draws it.
 *
 * The sentence is translated whole and then split on its own placeholder,
 * rather than concatenated from two halves: a translator sees "Colour for %s"
 * and can put the name wherever that language puts it. U+0001 is the marker
 * because it cannot occur in a folder name — sanitize_text_field strips
 * control characters server-side.
 */
function heading(name: string): React.ReactNode {
    const [before, after] = t('colorFor', 'Colour for %s', '').split('');

    return (
        <>
            {before}
            <strong>{name}</strong>
            {after ?? ''}
        </>
    );
}
