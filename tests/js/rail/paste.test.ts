import assert from 'node:assert/strict';
import { afterEach, describe, it } from 'node:test';

import { planPaste } from '../../../assets/src/apps/rail/paste';
import type { Clipboard } from '../../../assets/src/apps/rail/store';
import { f } from './tree';

/**
 *   Acme (1)
 *   Brand (2)            ← sort inside: set per test
 *     Logos (21)
 *       Primary (211)
 *     Print (22)
 *     Web (23)
 *   Campaigns (3)
 *
 * Passed as the roots on screen, already in the order the person sees.
 */
const tree = (brandSort: string | null = null) => [
    f(1, 'Acme'),
    f(2, 'Brand', [f(21, 'Logos', [f(211, 'Primary')]), f(22, 'Print'), f(23, 'Web')], {
        sort_folders: brandSort,
    }),
    f(3, 'Campaigns'),
];

const cut = (id: number): Clipboard => ({ id, name: `#${id}`, verb: 'cut', withFiles: false });
const copy = (id: number, withFiles = false): Clipboard => ({
    id,
    name: `#${id}`,
    verb: 'copy',
    withFiles,
});

afterEach(() => {
    delete window.folderFolio?.maxDepth;
});

describe('planPaste — cut, into a level not in Custom order', () => {
    it('is a plain move into another folder', () => {
        assert.deepEqual(planPaste(tree(), cut(3), 21, 'inside', 'name-asc'), {
            kind: 'move',
            id: 3,
            parentId: 21,
        });
    });

    it('is a plain move up to the top level, beside a root', () => {
        assert.deepEqual(planPaste(tree(), cut(22), 1, 'beside', 'name-asc'), {
            kind: 'move',
            id: 22,
            parentId: null,
        });
    });

    /*
     * No position is visible here, so pasting where it already is has
     * nothing to do — and the row is drawn disabled because this says null.
     */
    it('is nothing when the folder is already in that level', () => {
        assert.equal(planPaste(tree(), cut(22), 23, 'beside', 'name-asc'), null);
        assert.equal(planPaste(tree(), cut(22), 2, 'inside', 'name-asc'), null);
        assert.equal(planPaste(tree(), cut(22), 22, 'beside', 'name-asc'), null);
    });
});

describe('planPaste — cut, into a level in Custom order', () => {
    it('is a reorder placing it right after the target', () => {
        assert.deepEqual(planPaste(tree(), cut(3), 21, 'beside', 'custom'), {
            kind: 'reorder',
            parentId: 2,
            ids: [21, 3, 22, 23],
        });
    });

    it('inside a folder, is a reorder that puts it last', () => {
        assert.deepEqual(planPaste(tree(), cut(1), 2, 'inside', 'custom'), {
            kind: 'reorder',
            parentId: 2,
            ids: [21, 22, 23, 1],
        });
    });

    it('within its own level, takes it out of its old place first', () => {
        assert.deepEqual(planPaste(tree(), cut(21), 23, 'beside', 'custom'), {
            kind: 'reorder',
            parentId: 2,
            ids: [22, 23, 21],
        });
    });

    it('beside itself is nothing', () => {
        assert.equal(planPaste(tree(), cut(22), 22, 'beside', 'custom'), null);
    });
});

describe('planPaste — whose order decides', () => {
    it('the destination folder’s own order beats the global one — Custom inside', () => {
        const plan = planPaste(tree('custom'), cut(3), 21, 'beside', 'name-asc');

        assert.equal(plan?.kind, 'reorder');
    });

    it('…and the other way — a named order inside, under a Custom global', () => {
        const plan = planPaste(tree('name-desc'), cut(3), 21, 'beside', 'custom');

        assert.deepEqual(plan, { kind: 'move', id: 3, parentId: 2 });
    });

    it('the top level has no folder of its own, so the global order decides', () => {
        assert.equal(planPaste(tree('custom'), cut(22), 1, 'beside', 'name-asc')?.kind, 'move');
        assert.equal(planPaste(tree(), cut(22), 1, 'beside', 'custom')?.kind, 'reorder');
    });

    it('an order that is not one of the five falls back to the global one', () => {
        assert.equal(planPaste(tree('by-colour'), cut(3), 21, 'beside', 'custom')?.kind, 'reorder');
    });
});

describe('planPaste — copy', () => {
    it('is a duplicate into the target, with no position inside it', () => {
        assert.deepEqual(planPaste(tree(), copy(3), 2, 'inside', 'custom'), {
            kind: 'duplicate',
            id: 3,
            parentId: 2,
            withFiles: false,
        });
    });

    it('beside, in Custom order, carries the level with a 0 where the copy goes', () => {
        assert.deepEqual(planPaste(tree(), copy(22), 22, 'beside', 'custom'), {
            kind: 'duplicate',
            id: 22,
            parentId: 2,
            withFiles: false,
            order: [21, 22, 0, 23],
        });
    });

    it('beside, in any other order, carries no position', () => {
        const plan = planPaste(tree(), copy(22), 22, 'beside', 'name-asc');

        assert.equal(plan?.kind, 'duplicate');
        assert.ok(plan && !('order' in plan), 'no order outside Custom');
    });

    it('says whether the files come too', () => {
        const plan = planPaste(tree(), copy(21, true), 3, 'inside', 'name-asc');

        assert.equal(plan?.kind === 'duplicate' && plan.withFiles, true);
    });

    /*
     * A copy beside itself in a level that is not Custom is the ordinary
     * duplicate — not "already here", which is a cut's answer.
     */
    it('beside itself is the ordinary duplicate, not nothing', () => {
        assert.equal(planPaste(tree(), copy(22), 22, 'beside', 'name-asc')?.kind, 'duplicate');
    });
});

describe('planPaste — what the server would refuse', () => {
    it('is nothing inside itself, for cut and copy alike', () => {
        assert.equal(planPaste(tree(), cut(2), 2, 'inside', 'custom'), null);
        assert.equal(planPaste(tree(), copy(2), 2, 'inside', 'custom'), null);
    });

    it('is nothing anywhere below itself — inside a child, or beside a grandchild', () => {
        assert.equal(planPaste(tree(), cut(2), 21, 'inside', 'name-asc'), null);
        assert.equal(planPaste(tree(), copy(2), 211, 'beside', 'name-asc'), null);
    });

    /*
     * The same comparison FolderService::guardDepth() makes: a root is depth
     * 0, and what counts is where the deepest folder in the moved subtree
     * would land.
     */
    it('is nothing when the subtree would land deeper than the site allows', () => {
        window.folderFolio = { ...window.folderFolio, maxDepth: 2 } as typeof window.folderFolio;

        // Logos has one level under it. Inside Web (depth 1) puts Logos at 2
        // and Primary at 3.
        assert.equal(planPaste(tree(), cut(21), 23, 'inside', 'name-asc'), null);
        assert.equal(planPaste(tree(), copy(21), 23, 'inside', 'name-asc'), null);

        // Beside Web keeps Logos at 1 and Primary at 2: allowed.
        assert.notEqual(planPaste(tree(), copy(21), 23, 'beside', 'name-asc'), null);
        // …and inside a root is exactly the limit.
        assert.equal(planPaste(tree(), cut(21), 3, 'inside', 'name-asc')?.kind, 'move');
    });

    it('is nothing when what is held, or the target, is not in the tree', () => {
        assert.equal(planPaste(tree(), cut(99), 2, 'inside', 'name-asc'), null);
        assert.equal(planPaste(tree(), cut(3), 99, 'inside', 'name-asc'), null);
    });
});
