import assert from 'node:assert/strict';
import { afterEach, describe, it } from 'node:test';

import { hasLockInside, isBlocked, lockingName } from '../../../assets/src/apps/rail/locks';
import { f } from './tree';

function mayLock(value: boolean): void {
    window.folderFolio = { ...window.folderFolio, can: { ...window.folderFolio?.can, lock: value } } as typeof window.folderFolio;
}

/**
 *   Brand (1, locked)
 *     Logos (2)        — covered by Brand's lock
 *   Web (3)
 *     Icons (4, locked)
 */
const tree = () => [
    f(1, 'Brand', [f(2, 'Logos', [], { locked_by: 1 })], { locked: true, locked_by: 1 }),
    f(3, 'Web', [f(4, 'Icons', [], { locked: true, locked_by: 4 })]),
];

describe('locks — who a lock stops', () => {
    const before = window.folderFolio?.can;

    afterEach(() => {
        if (window.folderFolio) {
            window.folderFolio.can = before;
        }
    });

    it('stops someone without Lock at the locked folder and beneath it', () => {
        mayLock(false);
        const [brand, web] = tree();

        assert.equal(isBlocked(brand), true);
        assert.equal(isBlocked(brand.children[0]), true);
        assert.equal(isBlocked(web), false);
    });

    it('does not stop someone who holds Lock', () => {
        mayLock(true);

        assert.equal(isBlocked(tree()[0]), false);
    });

    it('names the folder whose lock covers a node, however deep', () => {
        const nodes = tree();

        assert.equal(lockingName(nodes, nodes[0].children[0]), 'Brand');
        assert.equal(lockingName(nodes, nodes[1].children[0]), 'Icons');
        assert.equal(lockingName(nodes, nodes[1]), '');
    });

    it('finds a lock anywhere beneath a folder — so it cannot be deleted', () => {
        mayLock(false);
        const [brand, web] = tree();

        assert.equal(hasLockInside(web), true);
        assert.equal(hasLockInside(brand.children[0]), false);
        mayLock(true);
        assert.equal(hasLockInside(web), false);
    });
});
