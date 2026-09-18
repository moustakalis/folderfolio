/**
 * The ten folder colours, client side.
 *
 * A folder stores a swatch *name*. The hex it resolves to belongs to the
 * admin colour scheme — `--ff-folder-teal` is #135e96 on Fresh and #9ec8d6 on
 * Midnight — so the row sets the custom property to `var(--ff-folder-<name>)`
 * and lets the cascade pick the right one. Writing a hex into the style
 * attribute, which is what this replaced, pinned every folder to whichever
 * scheme its colour was chosen on.
 *
 * This list is the same decision as `Support\Swatches::HEX` in PHP and the
 * `--ff-folder-*` block in `_tokens.css`. TokensTest asserts all three agree.
 */

import { t } from '../core/api';

export const SWATCHES = [
    'slate',
    'red',
    'clay',
    'ochre',
    'moss',
    'teal',
    'steel',
    'indigo',
    'plum',
    'ink',
] as const;

export type Swatch = (typeof SWATCHES)[number];

export function isSwatch(value: string | null | undefined): value is Swatch {
    return value !== null && value !== undefined && (SWATCHES as readonly string[]).includes(value);
}

/**
 * The style a coloured row carries, or undefined for no colour.
 *
 * Validated rather than interpolated blind. `node.color` arrives from the REST
 * response, and building `var(--ff-folder-${value})` out of an unchecked
 * string would put caller-controlled text inside a declaration — the server
 * constrains the column to these ten, and so does this, because the two
 * guards cost nothing and cover different failures.
 */
export function swatchStyle(value: string | null | undefined): React.CSSProperties | undefined {
    if (!isSwatch(value)) {
        return undefined;
    }

    return { '--ff-folder': `var(--ff-folder-${value})` } as React.CSSProperties;
}

/** The picker's label for a swatch. Translated; the name is not a colour code. */
export function swatchLabel(value: Swatch): string {
    switch (value) {
        case 'slate':
            return t('swatchSlate', 'Slate');
        case 'red':
            return t('swatchRed', 'Red');
        case 'clay':
            return t('swatchClay', 'Clay');
        case 'ochre':
            return t('swatchOchre', 'Ochre');
        case 'moss':
            return t('swatchMoss', 'Moss');
        case 'teal':
            return t('swatchTeal', 'Teal');
        case 'steel':
            return t('swatchSteel', 'Steel');
        case 'indigo':
            return t('swatchIndigo', 'Indigo');
        case 'plum':
            return t('swatchPlum', 'Plum');
        case 'ink':
            return t('swatchInk', 'Ink');
    }
}
