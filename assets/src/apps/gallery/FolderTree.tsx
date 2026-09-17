/**
 * The tree, at the block inspector's 268px — design step 9b.
 *
 * The whole of this file is a container class and an error state. That is the
 * point: the row's geometry has been parameterised since step 2, so the third
 * width is `.folderfolio-tree--inspector` restating three custom properties —
 * 32px rows, 20px indent, a 16px switcher — and the same Tree renders inside
 * it. No second implementation, which is what the handoff's two-cramped-cases
 * table was there to force.
 *
 * What the inspector does not get: create, rename and delete. The config this
 * screen ships says so (Blocks\Gallery::config), the toolbar is absent
 * entirely, and Tree's F2 and Delete keys check the same abilities.
 */

import { Tree } from '../rail/Tree';
import { useTree } from '../rail/queries';
import { t } from '../../core/api';

const noop = () => undefined;

export function FolderTree() {
    const { data, isPending, isError, refetch } = useTree();

    if (isError) {
        return (
            <div className="folderfolio-rail__error" role="alert">
                <p>{t('treeFailed', 'Could not load your folders.')}</p>
                <button
                    type="button"
                    className="folderfolio-rail__ghost"
                    onClick={() => void refetch()}
                >
                    {t('retry', 'Retry')}
                </button>
            </div>
        );
    }

    return (
        <div className="folderfolio folderfolio-tree--inspector folderfolio-inspector">
            <Tree
                nodes={data ?? []}
                loading={isPending}
                onSaveEdit={noop}
                onCancelEdit={noop}
                onDelete={noop}
            />
        </div>
    );
}
