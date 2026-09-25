import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { t, tn } from '../../../assets/src/core/api';
import { pickForm, pluralIndex } from '../../../assets/src/core/plural';

// The expressions as the languages' .po headers state them.
const POLISH = '(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2)';
const RUSSIAN = '(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);';
const ARABIC = 'n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5';

const indices = (rule: string, counts: number[]): number[] => counts.map((n) => pluralIndex(n, rule));

describe('pluralIndex — a Plural-Forms expression, evaluated', () => {
    it('is English with no rule, or the default one', () => {
        assert.deepEqual(indices('n != 1', [0, 1, 2, 11]), [1, 0, 1, 1]);
        assert.deepEqual(indices('', [0, 1, 2]), [1, 0, 1]);
    });

    it('is French, where none is singular', () => {
        assert.deepEqual(indices('n > 1', [0, 1, 2]), [0, 0, 1]);
    });

    it('is Polish, three forms', () => {
        assert.deepEqual(indices(POLISH, [1, 2, 4, 5, 12, 14, 21, 22, 25, 0]), [0, 1, 1, 2, 2, 2, 2, 1, 2, 2]);
    });

    // The trailing semicolon is how many headers end.
    it('is Russian, where 21 is singular and 11 is not', () => {
        assert.deepEqual(indices(RUSSIAN, [1, 21, 11, 3, 13, 5]), [0, 0, 2, 1, 2, 2]);
    });

    it('is Arabic, six forms, right-associative ternaries', () => {
        assert.deepEqual(indices(ARABIC, [0, 1, 2, 3, 10, 11, 99, 100, 102, 103]), [0, 1, 2, 3, 3, 4, 4, 5, 5, 3]);
    });

    it('is a constant for a language with one form', () => {
        assert.deepEqual(indices('0', [0, 1, 5]), [0, 0, 0]);
    });

    it('falls back to English on anything core would not have parsed', () => {
        assert.deepEqual(indices('n ** 2', [1, 2]), [0, 1]);
        assert.deepEqual(indices('alert(1)', [1, 2]), [0, 1]);
        assert.deepEqual(indices('(n == 1', [1, 2]), [0, 1]);
    });
});

describe('pickForm', () => {
    it('clamps an index past the forms it has to the last', () => {
        assert.equal(pickForm(['one', 'other'], 5, POLISH), 'other');
    });
});

describe('tn — every form the translation has', () => {
    const polish = (): void => {
        window.folderFolio = {
            pluralRule: POLISH,
            i18n: { fileSelected: ['%s plik zaznaczony', '%s pliki zaznaczone', '%s plików zaznaczonych'] },
        } as unknown as typeof window.folderFolio;
    };

    it('picks the form the count takes and fills the count', () => {
        polish();
        const pick = (n: number): string => tn('fileSelected', 'filesSelected', n, '%s file selected', '%s files selected', n);

        assert.equal(pick(1), '1 plik zaznaczony');
        assert.equal(pick(3), '3 pliki zaznaczone');
        assert.equal(pick(5), '5 plików zaznaczonych');
        assert.equal(pick(22), '22 pliki zaznaczone');
    });

    it('fills positional placeholders, the count not necessarily first', () => {
        window.folderFolio = {
            pluralRule: 'n != 1',
            i18n: { movedFile: ['Moved %1$s file to %2$s', 'Moved %1$s files to %2$s'] },
        } as unknown as typeof window.folderFolio;

        assert.equal(tn('movedFile', 'movedFiles', 2, '', '', 2, 'Launch'), 'Moved 2 files to Launch');
    });

    it('is English over its fallbacks when the entry is not there', () => {
        window.folderFolio = { i18n: {} } as unknown as typeof window.folderFolio;

        assert.equal(tn('x', 'xs', 1, '%s file', '%s files', 1), '1 file');
        assert.equal(tn('x', 'xs', 0, '%s file', '%s files', 0), '0 files');
    });

    it('never renders a plural entry as a plain label', () => {
        polish();

        assert.equal(t('fileSelected', 'fallback'), 'fallback');
    });
});
