import assert from 'node:assert/strict';
import { afterEach, describe, it } from 'node:test';

import { planFolderDrop, type Visible } from '../../../assets/src/apps/rail/folder-drop';
import type { FolderNode } from '../../../assets/src/apps/rail/queries';
import { f } from './tree';

/**
 *   A (1)
 *     A1 (2)
 *       A1a (3)
 *   B (4)            — locked, in the lock case
 */
function tree(lockB = false): { roots: FolderNode[]; visible: Visible[] } {
    const a1a = f(3, 'A1a');
    const a1 = f(2, 'A1', [a1a]);
    const a = f(1, 'A', [a1]);
    const b = f(4, 'B', [], lockB ? { locked: true, locked_by: 4 } : {});
    const roots = [a, b] as unknown as FolderNode[];
    const visible = [
        { node: a, depth: 0 },
        { node: a1, depth: 1 },
        { node: a1a, depth: 2 },
        { node: b, depth: 0 },
    ] as unknown as Visible[];

    return { roots, visible };
}

describe('planFolderDrop — where a dragged folder may land (review L10)', () => {
    const before = window.folderFolio;

    afterEach(() => {
        window.folderFolio = before;
    });

    it('refuses any gap inside the dragged folder’s own subtree', () => {
        const { roots, visible } = tree();

        // After A1a, one deeper: inside A1a, which is inside A.
        assert.equal(planFolderDrop(visible, roots, 3, 3, 1), null);
        // Inside A1.
        assert.equal(planFolderDrop(visible, roots, 2, 2, 1), null);
    });

    it('still takes a drop into another branch', () => {
        const { roots, visible } = tree();

        assert.deepEqual(planFolderDrop(visible, roots, 1, 1, 4)?.parentId, 1);
    });

    it('refuses a drop that would nest deeper than the server allows', () => {
        const { roots, visible } = tree();
        window.folderFolio = { ...before, maxDepth: 1 } as typeof window.folderFolio;

        // A is two levels tall; under B it would reach depth 3.
        assert.equal(planFolderDrop(visible, roots, 4, 1, 1), null);
    });

    it('refuses a drop into a folder whose lock stops this person', () => {
        const { roots, visible } = tree(true);
        window.folderFolio = { ...before, can: { ...before?.can, lock: false } } as typeof window.folderFolio;

        assert.equal(planFolderDrop(visible, roots, 4, 1, 3), null);
    });
});
