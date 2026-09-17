/**
 * The rail header — screen 03: an eyebrow label and the primary action.
 *
 * "New folder" opens an input in the tree, in the row where the folder will
 * live, rather than a block above it or a dialog. Where it will land is then
 * answered by position, which is the question every plugin tested answers
 * differently and silently. Screen 05 draws it exactly there.
 */

import { PlusIcon } from './icons';
import { useRail } from './store';
import { t } from '../../core/api';

export function Header() {
    const selectedId = useRail((s) => s.selectedId);
    const expand = useRail((s) => s.expand);
    const edit = useRail((s) => s.edit);

    function startCreate() {
        // `null` is All media and `0` is Unassigned — neither is a folder, so
        // both mean "at the top level". Only a positive id is a parent.
        const parentId = selectedId !== null && selectedId > 0 ? selectedId : null;

        if (parentId !== null) {
            // Otherwise the new row would be created inside a folder that is
            // closed, and nothing would appear.
            expand(parentId);
        }

        edit({ mode: 'create', parentId, folderId: null, value: '' });
    }

    return (
        <div className="folderfolio-rail__header">
            <span className="folderfolio-rail__eyebrow">{t('folders', 'Folders')}</span>

            <button type="button" className="folderfolio-rail__primary" onClick={startCreate}>
                <PlusIcon size={13} />
                {t('newFolder', 'New folder')}
            </button>
        </div>
    );
}
