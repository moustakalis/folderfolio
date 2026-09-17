/**
 * The rail header — screen 03: an eyebrow label and the primary action.
 *
 * "New folder" opens an inline row rather than a dialog. That is the design's
 * mechanic (screen 05) and it is here at step 4 rather than step 6 for a
 * simple reason: the alternative was shipping a button that does nothing, or
 * keeping v0.2.0's window.prompt(), which is one of the six deltas on screen
 * 00. A header whose only control is dead is worse than either.
 */

import { useEffect, useRef, useState } from 'react';

import { PlusIcon } from './icons';
import { useCreateFolder } from './queries';
import { useRail } from './store';
import { t } from '../../core/api';

export function Header() {
    const [creating, setCreating] = useState(false);

    return (
        <>
            <div className="folderfolio-rail__header">
                <span className="folderfolio-rail__eyebrow">{t('folders', 'Folders')}</span>

                <button
                    type="button"
                    className="folderfolio-rail__primary"
                    onClick={() => setCreating(true)}
                >
                    <PlusIcon size={13} />
                    {t('newFolder', 'New folder')}
                </button>
            </div>

            {creating ? <CreateRow onDone={() => setCreating(false)} /> : null}
        </>
    );
}

/**
 * The inline create row.
 *
 * It sits where the folder will live and says so, because "New folder" with a
 * tree on screen is ambiguous — inside the selected folder, or at the root? —
 * and every plugin tested answers that question differently and silently.
 *
 * Explicit Save and Cancel, and Enter and Escape. FileBird ends creation with
 * buttons; the keyboard alone is not discoverable, and a field that commits on
 * blur loses what you typed when you reach for anything else.
 */
function CreateRow({ onDone }: { onDone: () => void }) {
    const selectedId = useRail((s) => s.selectedId);
    const select = useRail((s) => s.select);
    const reveal = useRail((s) => s.reveal);
    const create = useCreateFolder();

    const [name, setName] = useState('');
    const input = useRef<HTMLInputElement>(null);

    useEffect(() => input.current?.focus(), []);

    // `null` is All media and `0` is Unassigned — neither is a folder, so both
    // mean "at the root". Only a positive id is a parent.
    const parentId = selectedId !== null && selectedId > 0 ? selectedId : null;

    function save() {
        const trimmed = name.trim();

        if (trimmed === '' || create.isPending) {
            return;
        }

        create.mutate(
            { name: trimmed, parentId },
            {
                onSuccess: (folder) => {
                    if (parentId !== null) {
                        reveal([parentId]);
                    }

                    select(folder.id);
                    onDone();
                },
            }
        );
    }

    return (
        <div className="folderfolio-rail__inline">
            <label className="folderfolio-rail__inline-parent">
                {parentId === null
                    ? t('createAtRoot', 'New folder at the top level')
                    : t('createInFolder', 'New folder inside the selected folder')}
            </label>

            <input
                ref={input}
                type="text"
                className="folderfolio-row__input"
                value={name}
                disabled={create.isPending}
                onChange={(event) => setName(event.target.value)}
                onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        save();
                    }

                    if (event.key === 'Escape') {
                        event.preventDefault();
                        onDone();
                    }
                }}
            />

            <div className="folderfolio-rail__inline-actions">
                <button
                    type="button"
                    className="folderfolio-rail__primary"
                    onClick={save}
                    disabled={name.trim() === '' || create.isPending}
                >
                    {t('save', 'Save')}
                </button>
                <button type="button" className="folderfolio-rail__ghost" onClick={onDone}>
                    {t('cancel', 'Cancel')}
                </button>
            </div>

            {/*
              Said where it was attempted, not in a toast at the other end of
              the screen — and the typed name is still in the field, so the
              retry is one keystroke.
            */}
            {create.isError ? (
                <p className="folderfolio-rail__error" role="alert">
                    {t('createFailed', 'Could not create that folder.')}
                </p>
            ) : null}
        </div>
    );
}
