import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { clampToPinGroup, planSiblingMove } from '../../../assets/src/apps/rail/move';
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

describe('pinned folders keep to their group (tier 2 item 10)', () => {
    // As sortTree draws it: the pinned first.
    const level = () => [f(5, 'P1', [], { pinned: true }), f(6, 'P2', [], { pinned: true }), f(7, 'U1'), f(8, 'U2')];

    it('will not step an unpinned folder above the last pinned one, or a pinned one below', () => {
        assert.equal(planSiblingMove(level(), 7, -1), null);
        assert.equal(planSiblingMove(level(), 6, 1), null);
    });

    it('still steps within a group', () => {
        assert.deepEqual(planSiblingMove(level(), 6, -1), { parentId: null, ids: [6, 5, 7, 8] });
        assert.deepEqual(planSiblingMove(level(), 7, 1), { parentId: null, ids: [5, 6, 8, 7] });
    });

    it('lands a drop at the nearest place it will be seen', () => {
        // An unpinned folder dropped at the top goes after the pinned.
        assert.equal(clampToPinGroup(level(), { id: 8 }, 0), 2);
        // A pinned folder dropped among the unpinned goes last of the pinned.
        assert.equal(clampToPinGroup(level(), { id: 5, pinned: true }, 3), 1);
        // A pinned folder from elsewhere, dropped at the end of this level.
        assert.equal(clampToPinGroup(level(), { id: 99, pinned: true }, 4), 2);
        // Inside its group, where it was aimed.
        assert.equal(clampToPinGroup(level(), { id: 8 }, 3), 3);
    });
});
