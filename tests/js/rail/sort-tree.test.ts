import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { sortTree } from '../../../assets/src/apps/rail/store';
import { f, names, outline } from './tree';

const roots = () => [
    f(3, 'Folder 10', [], { sort_order: 0 }),
    f(1, 'folder 2', [], { sort_order: 2 }),
    f(2, 'Archive', [], { sort_order: 1 }),
];

describe('sortTree — the five orders', () => {
    it('Name, A to Z is numeric and ignores case', () => {
        assert.deepEqual(names(sortTree(roots(), 'name-asc')), ['Archive', 'folder 2', 'Folder 10']);
    });

    it('Name, Z to A is its exact reverse', () => {
        assert.deepEqual(names(sortTree(roots(), 'name-desc')), ['Folder 10', 'folder 2', 'Archive']);
    });

    it('Newest and Oldest go by id, the order the database made them in', () => {
        assert.deepEqual(names(sortTree(roots(), 'newest')), ['Folder 10', 'Archive', 'folder 2']);
        assert.deepEqual(names(sortTree(roots(), 'oldest')), ['folder 2', 'Archive', 'Folder 10']);
    });

    it('Custom goes by sort_order', () => {
        assert.deepEqual(names(sortTree(roots(), 'custom')), ['Folder 10', 'Archive', 'folder 2']);
    });

    /*
     * Every folder starts at 0. A level nobody has dragged has to read as
     * alphabetical under Custom, not in id order.
     */
    it('Custom breaks a sort_order tie by name, not by id', () => {
        // Ids in neither name order nor its reverse, so an id tie-break cannot pass.
        const untouched = [f(7, 'Zebra'), f(9, 'Mango'), f(8, 'Apple')];

        assert.deepEqual(names(sortTree(untouched, 'custom')), ['Apple', 'Mango', 'Zebra']);
    });

    it('does not change the array it was given', () => {
        const given = roots();

        sortTree(given, 'name-desc');

        assert.deepEqual(names(given), ['Folder 10', 'folder 2', 'Archive']);
    });
});

describe('sortTree — a folder’s own order', () => {
    /*
     *   A (sort inside: Z to A)
     *     A1, A2 — and A2 holds a1, a2, whose parent says nothing
     *   B (no order of its own)
     *     B1, B2
     */
    const tree = () => [
        f(2, 'B', [f(22, 'B2'), f(21, 'B1')]),
        f(
            1,
            'A',
            [f(11, 'A1'), f(12, 'A2', [f(122, 'a2'), f(121, 'a1')])],
            { sort_folders: 'name-desc' }
        ),
    ];

    it('governs its children', () => {
        const sorted = sortTree(tree(), 'name-asc');

        assert.deepEqual(names(sorted[0].children), ['A2', 'A1']);
    });

    it('does not reach its siblings', () => {
        assert.deepEqual(names(sortTree(tree(), 'name-asc')), ['A', 'B']);
        assert.deepEqual(names(sortTree(tree(), 'name-asc')[1].children), ['B1', 'B2']);
    });

    /*
     * The parent case the store's comment names: "Sort inside A by Z–A" is
     * about what is inside A, not inside A's subfolders. Below it, the GLOBAL
     * order — not A's.
     */
    it('does not reach its grandchildren — they fall back to the global order', () => {
        assert.deepEqual(outline(sortTree(tree(), 'name-asc')), [
            'A',
            '  A2',
            '    a1',
            '    a2',
            '  A1',
            'B',
            '  B1',
            '  B2',
        ]);
    });

    it('a value that is not one of the five is treated as none', () => {
        const odd = [f(1, 'P', [f(11, 'x2'), f(12, 'x1')], { sort_folders: 'by-colour' })];

        assert.deepEqual(names(sortTree(odd, 'name-asc')[0].children), ['x1', 'x2']);
    });

    it('the global order changes what falls back to it and nothing else', () => {
        const sorted = sortTree(tree(), 'name-desc');

        assert.deepEqual(names(sorted), ['B', 'A']);
        assert.deepEqual(names(sorted[0].children), ['B2', 'B1']);
        assert.deepEqual(names(sorted[1].children), ['A2', 'A1']);
    });
});
