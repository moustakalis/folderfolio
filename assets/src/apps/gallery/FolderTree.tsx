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
 * What the inspector does not get: create, rename and delete. This bundle
 * withholds them (`restrictAbilities()` in apps/gallery.tsx, lib/can.ts) —
 * not the config, which the media picker on the same screen reads too — the
 * toolbar is absent entirely, and Tree's F2 and Delete keys check the same
 * abilities.
 */

import { useMemo } from 'react';

import { ImageIcon } from '../rail/icons';
import { Tree } from '../rail/Tree';
import { useTree, type FolderNode } from '../rail/queries';
import { useRail } from '../rail/store';
import { t } from '../../core/api';

const noop = () => undefined;

interface Gallery {
    node: FolderNode;
    parentName: string | null;
    ancestorIds: number[];
}

/** Every gallery in the tree, in tree order, with what a shortcut needs. */
function galleriesIn(nodes: readonly FolderNode[]): Gallery[] {
    const out: Gallery[] = [];

    const walk = (level: readonly FolderNode[], parent: FolderNode | null, ancestors: number[]) => {
        for (const node of level) {
            if (node.kind === 'gallery') {
                out.push({ node, parentName: parent?.name ?? null, ancestorIds: ancestors });
            }

            walk(node.children, node, [...ancestors, node.id]);
        }
    };

    walk(nodes, null, []);

    return out;
}

/**
 * Galleries first — tier 3 item 14, board KZsHhrffzKQYqUjTvdFszK (14a).
 *
 * A gallery is a folder somebody made for this block, so it is listed above
 * the tree, flat, wherever it sits in it; a nested one names its parent, as a
 * starred shortcut does. Choosing one selects it in the tree below and opens
 * the way to it. Nothing at all when there are no galleries — the picker is
 * then exactly what it was.
 */
function Galleries({ nodes }: { nodes: readonly FolderNode[] }) {
    const galleries = useMemo(() => galleriesIn(nodes), [nodes]);
    const selectedId = useRail((s) => s.selectedId);
    const select = useRail((s) => s.select);
    const reveal = useRail((s) => s.reveal);

    if (galleries.length === 0) {
        return null;
    }

    return (
        <div className="folderfolio-inspector__galleries" role="group" aria-label={t('galleriesGroup', 'Galleries')}>
            <p className="folderfolio-inspector__label" aria-hidden="true">
                {t('galleriesGroup', 'Galleries')}
            </p>

            {galleries.map(({ node, parentName, ancestorIds }) => (
                <button
                    key={node.id}
                    type="button"
                    className="folderfolio-row folderfolio-inspector__gallery"
                    aria-selected={selectedId === node.id}
                    aria-label={parentName === null ? node.name : t('galleryIn', '%1$s in %2$s', node.name, parentName)}
                    onClick={() => {
                        reveal(ancestorIds);
                        select(node.id);
                    }}
                >
                    <span className="folderfolio-row__icon">
                        <ImageIcon />
                    </span>
                    <span className="folderfolio-row__name">
                        {node.name}
                        {parentName === null ? null : (
                            <span className="folderfolio-inspector__parent"> · {parentName}</span>
                        )}
                    </span>
                </button>
            ))}

            <p className="folderfolio-inspector__label" aria-hidden="true">
                {t('allFoldersGroup', 'All folders')}
            </p>
        </div>
    );
}

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
            <Galleries nodes={data ?? []} />
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
