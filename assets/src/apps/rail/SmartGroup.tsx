/**
 * The Smart group — tier 3 item 13, Nick's 13b on board KZsHhrffzKQYqUjTvdFszK.
 *
 * Saved views, shared by the site, under Starred and above the tree. A row
 * chooses the view the way a folder row chooses a folder, and the library is
 * filtered by the view's rules on the server (Domain\SmartRules). Rows are
 * not drop targets: nothing is filed into a rule. The dashed icon says so
 * before anyone tries.
 *
 * Made and changed by roles with Organise. For them the group ends with a
 * compact *New smart folder* row, empty or not; for everyone else an empty
 * group is nothing at all, so no rail changes until somebody saves a view.
 */

import { useEffect, useState } from 'react';

import { PencilIcon, PlusIcon, SmartFolderIcon } from './icons';
import { useSmartFolders, type FolderNode, type SmartFolder } from './queries';
import { SmartEditor } from './SmartEditor';
import { useRail } from './store';
import { can } from '../../lib/can';
import { t } from '../../core/api';

export function SmartGroup({ nodes }: { nodes: readonly FolderNode[] }) {
    const { data } = useSmartFolders();
    const smartId = useRail((s) => s.smartId);
    const selectSmart = useRail((s) => s.selectSmart);
    const [editing, setEditing] = useState<SmartFolder | 'new' | null>(null);

    const items = data ?? [];
    const organise = can('rename');

    // A link to a smart folder somebody has since deleted lands on All media,
    // with the address bar cleaned, as a link to a deleted folder does — not
    // on an empty library under a crumb that names nothing.
    useEffect(() => {
        if (data !== undefined && smartId !== null && !data.some((item) => item.id === smartId)) {
            selectSmart(null);
        }
    }, [data, smartId, selectSmart]);

    if (items.length === 0 && !organise) {
        return null;
    }

    return (
        <div className="folderfolio-rail__smart" role="group" aria-label={t('smartGroup', 'Smart folders')}>
            <p className="folderfolio-rail__starred-label" aria-hidden="true">
                {t('smartLabel', 'Smart')}
            </p>

            {items.length === 0 ? null : (
                <div className="folderfolio-rail__starred-list">
                    {items.map((item) => {
                        const selected = smartId === item.id;

                        return (
                            <div key={item.id} className={`folderfolio-rail__smart-item${selected ? ' is-selected' : ''}`}>
                                <button
                                    type="button"
                                    className="folderfolio-row folderfolio-rail__fixed-row folderfolio-rail__smart-row"
                                    aria-selected={selected}
                                    aria-label={`${item.name}, ${item.count}`}
                                    onClick={() => selectSmart(item.id)}
                                >
                                    <span className="folderfolio-row__icon">
                                        <SmartFolderIcon />
                                    </span>
                                    <span className="folderfolio-row__name">{item.name}</span>
                                    <span className="folderfolio-row__count">{item.count}</span>
                                </button>

                                {/* On the selected row only, like the folder ⋮ —
                                    one targeting model for "act on this". */}
                                {organise && selected ? (
                                    <button
                                        type="button"
                                        className="folderfolio-rail__smart-edit"
                                        title={t('editSmartFolder', 'Edit smart folder')}
                                        aria-label={t('editSmartNamed', 'Edit “%s”', item.name)}
                                        onClick={() => setEditing(item)}
                                    >
                                        <PencilIcon size={13} />
                                    </button>
                                ) : null}
                            </div>
                        );
                    })}
                </div>
            )}

            {/*
              The way to make one, for Organise, whether the group is empty or
              not: a compact row after the list, outside its scroll so it never
              scrolls away. It replaced a + in the heading (Nick, 24 Sep, option
              C on board 6b3bwm7vPh6NMu3kmyxBCt) — the only unlabelled control
              in a heading, aligned with nothing on screen. 24px, the smallest
              target WCAG 2.5.8 allows, because he asked for it as small as
              possible.
            */}
            {organise ? (
                <button
                    type="button"
                    className="folderfolio-row folderfolio-rail__fixed-row folderfolio-rail__smart-new"
                    onClick={() => setEditing('new')}
                >
                    <span className="folderfolio-row__icon">
                        <PlusIcon size={12} />
                    </span>
                    <span className="folderfolio-row__name">{t('newSmartFolder', 'New smart folder')}</span>
                </button>
            ) : null}

            {editing === null ? null : (
                <SmartEditor
                    smart={editing === 'new' ? null : editing}
                    nodes={nodes}
                    onClose={() => setEditing(null)}
                />
            )}
        </div>
    );
}
