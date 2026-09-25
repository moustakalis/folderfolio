/**
 * The rail header — screen 03: an eyebrow label and the primary action.
 *
 * "New folder" opens an input in the tree, in the row where the folder will
 * live, rather than a block above it or a dialog. Where it will land is then
 * answered by position, which is the question every plugin tested answers
 * differently and silently. Screen 05 draws it exactly there.
 */

import { BrandMark, PlusIcon } from './icons';
import { can } from '../../lib/can';
import { useIsNarrow } from '../../lib/narrow';
import { useRail } from './store';
import { t } from '../../core/api';

export function Header() {
    const selectedId = useRail((s) => s.selectedId);
    const expand = useRail((s) => s.expand);
    const edit = useRail((s) => s.edit);
    const levelId = useRail((s) => s.levelId);
    const narrow = useIsNarrow();

    function startCreate() {
        // `null` is All media and `0` is Unassigned — neither is a folder, so
        // both mean "at the top level". Only a positive id is a parent.
        //
        // On the narrow sheet the input opens at the top of the level on
        // screen, so that level is the parent — not the selection, which can
        // be a folder without children shown as a row inside it (review H2).
        const parentId = narrow
            ? levelId
            : selectedId !== null && selectedId > 0 ? selectedId : null;

        if (parentId !== null) {
            // Otherwise the new row would be created inside a folder that is
            // closed, and nothing would appear.
            expand(parentId);
        }

        edit({ mode: 'create', parentId, folderId: null, value: '' });
    }

    return (
        <div className="folderfolio-rail__header">
            {/*
              The product's name, not "Folders" as the board draws it: this is
              the one place in wp-admin the plugin is identified, and a generic
              word there names the region twice — the collapsed tab already
              says FOLDERS, and the region has an accessible name from the
              rail's landmark. Not run through t(): a brand is not translated.

              The mark sits in front of it, which is what makes the pair read
              as a lockup rather than as a word in small caps — and it is the
              same shape as the block icon and the wordpress.org listing.
            */}
            <span className="folderfolio-rail__brand">
                <BrandMark size={16} />
                <span className="folderfolio-rail__eyebrow">FolderFolio</span>
            </span>

            {/* Hidden rather than disabled: an always-grey primary action in
                the corner of every media screen is a permanent reminder of
                something this user is never going to be able to do. Rename and
                Delete in the toolbar are disabled instead, because they are
                grey most of the time anyway — nothing is selected. */}
            {can('create') && (
                <button type="button" className="folderfolio-rail__primary" onClick={startCreate}>
                    <PlusIcon size={13} />
                    {t('newFolder', 'New folder')}
                </button>
            )}
        </div>
    );
}
