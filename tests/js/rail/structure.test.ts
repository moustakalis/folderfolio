import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { chunk, isInDirectory, isLitter, planStructure } from '../../../assets/src/lib/structure';

describe('planStructure — the folders a dropped directory needs', () => {
    it('makes each directory that holds a file once, in the order met', () => {
        const plan = planStructure([
            '/Brand/Logos/primary.png',
            '/Brand/readme.pdf',
            '/Brand/Logos/mark.svg',
            '/Brand/Icons/Line/arrow.svg',
        ]);

        assert.deepEqual(plan.paths, ['Brand/Logos', 'Brand', 'Brand/Icons/Line']);
        assert.deepEqual(plan.place, [0, 1, 0, 2]);
    });

    // A file dropped beside the directory goes where a plain upload goes.
    it('leaves a loose file where a plain upload would go', () => {
        const plan = planStructure(['/cover.jpg', '', 'Brand/a.png']);

        assert.deepEqual(plan.paths, ['Brand']);
        assert.deepEqual(plan.place, [-1, -1, 0]);
    });

    it('leaves desktop litter behind, inside a directory only', () => {
        const plan = planStructure([
            '/Brand/.DS_Store',
            '/Brand/.git/HEAD',
            '/Brand/Thumbs.db',
            '/Brand/__MACOSX/a.png',
            '/Brand/DESKTOP.INI',
            '/.env',
            '/Brand/a.png',
        ]);

        assert.deepEqual(plan.place, [null, null, null, null, null, -1, 0]);
        assert.deepEqual(plan.paths, ['Brand'], 'no folder for anything left behind');
    });

    it('treats doubled and trailing separators as one', () => {
        const plan = planStructure(['//Brand//Logos/a.png', 'Brand/Logos/b.png']);

        assert.deepEqual(plan.paths, ['Brand/Logos']);
        assert.deepEqual(plan.place, [0, 0]);
    });

    // A newline would split one line of the bulk text into two folders.
    it('makes a newline in a directory name a space', () => {
        const plan = planStructure(['/Two\nLines/a.png']);

        assert.deepEqual(plan.paths, ['Two Lines']);
    });
});

describe('isInDirectory', () => {
    it('is true only for a file with a directory above it', () => {
        assert.equal(isInDirectory('/Brand/a.png'), true);
        assert.equal(isInDirectory('/a.png'), false);
        assert.equal(isInDirectory(''), false);
    });
});

describe('isLitter', () => {
    // A dotfile dropped on its own was chosen by name.
    it('is litter only inside a dropped directory', () => {
        assert.equal(isLitter('/Brand/.DS_Store'), true);
        assert.equal(isLitter('/Brand/__MACOSX/._a.jpg'), true);
        assert.equal(isLitter('/.DS_Store'), false);
        assert.equal(isLitter('/Brand/a.png'), false);
    });
});

describe('chunk', () => {
    it('splits into turns of the given size', () => {
        assert.deepEqual(chunk([1, 2, 3, 4, 5], 2), [[1, 2], [3, 4], [5]]);
        assert.deepEqual(chunk([], 500), []);
    });
});
