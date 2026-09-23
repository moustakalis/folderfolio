import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { planSiblingMove } from '../../../assets/src/apps/rail/move';
import { f } from './tree';

/**
 *   Archive (1)
 *   Brand (2)
 *     Logos (21)
 *     Print (22)
 *     Web (23)
 *   Campaigns (3)
 */
const tree = () => [
    f(1, 'Archive'),
    f(2, 'Brand', [f(21, 'Logos'), f(22, 'Print'), f(23, 'Web')]),
    f(3, 'Campaigns'),
];

describe('planSiblingMove', () => {
    it('moves a root one place down and sends the whole top level', () => {
        assert.deepEqual(planSiblingMove(tree(), 1, 1), { parentId: null, ids: [2, 1, 3] });
    });

    it('moves a nested folder one place up and names its parent', () => {
        assert.deepEqual(planSiblingMove(tree(), 23, -1), { parentId: 2, ids: [21, 23, 22] });
    });

    it('sends only the level the folder is in', () => {
        const plan = planSiblingMove(tree(), 22, 1);

        assert.deepEqual(plan?.ids, [21, 23, 22]);
        assert.ok(!plan?.ids.includes(2), 'the parent is not a sibling');
    });

    it('is null at the ends of a level — the direction that would leave it', () => {
        assert.equal(planSiblingMove(tree(), 1, -1), null);
        assert.equal(planSiblingMove(tree(), 3, 1), null);
        assert.equal(planSiblingMove(tree(), 21, -1), null);
        assert.equal(planSiblingMove(tree(), 23, 1), null);
    });

    it('is null for a folder that is not in the tree', () => {
        assert.equal(planSiblingMove(tree(), 99, 1), null);
    });

    it('is null for an only child, both ways', () => {
        const only = [f(1, 'Solo', [f(11, 'Inside')])];

        assert.equal(planSiblingMove(only, 11, 1), null);
        assert.equal(planSiblingMove(only, 11, -1), null);
    });

    /*
     * The order of the array it is given is the order it moves in: "up" is
     * the row above on screen, and under Name, Z to A that is not the row
     * above in sort_order. Callers pass sortTree()'s output; this asserts the
     * function does not quietly re-sort.
     */
    it('moves in the order it is given, not by id or name', () => {
        const onScreen = [f(3, 'Campaigns'), f(2, 'Brand'), f(1, 'Archive')];

        assert.deepEqual(planSiblingMove(onScreen, 2, -1), { parentId: null, ids: [2, 3, 1] });
    });

    it('does not change the tree it was given', () => {
        const given = tree();
        const before = JSON.stringify(given);

        planSiblingMove(given, 22, 1);

        assert.equal(JSON.stringify(given), before);
    });
});
