import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { planTileDrop, sideOf } from '../../../assets/src/apps/rail/file-order';

describe('planTileDrop — a drop between two tiles', () => {
    it('places the dragged files beside the tile, on the side the pointer was', () => {
        assert.deepEqual(planTileDrop(12, [5, 9], 3, 'after'), {
            folderId: 12,
            ids: [5, 9],
            place: 'after',
            anchor: 3,
        });
    });

    it('is nothing when dropped beside one of the files being dragged', () => {
        assert.equal(planTileDrop(12, [5, 9], 9, 'before'), null);
    });

    // All media is null and Unassigned is 0: neither has an order to keep.
    it('is nothing outside a folder', () => {
        assert.equal(planTileDrop(0, [5], 3, 'before'), null);
    });

    it('is nothing with nothing dragged', () => {
        assert.equal(planTileDrop(12, [], 3, 'before'), null);
    });
});

describe('sideOf — which half of a tile', () => {
    it('the left half is before, the right half after', () => {
        assert.equal(sideOf(110, 100, 150), 'before');
        assert.equal(sideOf(174, 100, 150), 'before');
        assert.equal(sideOf(175, 100, 150), 'after');
        assert.equal(sideOf(249, 100, 150), 'after');
    });
});
