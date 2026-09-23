/**
 * What this user may do to folders.
 *
 * The answers come from the roles matrix on the settings screen, resolved
 * server-side by Capabilities and handed to the client in window.folderFolio.
 * Every one of them is enforced again on the REST route — this is what turns
 * a 403 nobody asked for into a button that is visibly unavailable.
 *
 * Defaults are permissive. A missing `can` object means an older bundle or a
 * screen whose config predates the matrix; denying everything there would
 * disable the whole toolbar on a screen that used to work, which is a worse
 * failure than showing a button that the server will refuse.
 */

export type Ability = 'create' | 'rename' | 'delete' | 'assign' | 'lock' | 'download' | 'star';

/*
 * `star` is not a column in the roles matrix: everyone who can see folders can
 * star one, so the server never sends it and it defaults to true. It is here
 * so that a bundle that offers less can withhold it like any other — the
 * gallery inspector's read-only tree has no ⋮ because `restrictAbilities()`
 * leaves it nothing, star included.
 */

/**
 * Abilities this bundle has chosen not to offer, whatever the user may do.
 *
 * Module state, and that is the point: each app is its own esbuild bundle
 * (`format: 'iife'`, no splitting), so each has its own copy of this module.
 * The gallery block's inspector narrows itself here to a read-only tree
 * without touching `window.folderFolio` — which the media picker on the same
 * screen reads too, and which until 23 Sep the gallery filled with `false`s
 * and made the picker read-only for an administrator.
 */
let withheld: ReadonlySet<Ability> = new Set();

export function restrictAbilities(allowed: readonly Ability[]): void {
    const all: Ability[] = ['create', 'rename', 'delete', 'assign', 'lock', 'download', 'star'];

    withheld = new Set(all.filter((ability) => !allowed.includes(ability)));
}

export function can(ability: Ability): boolean {
    if (withheld.has(ability)) {
        return false;
    }

    return (window.folderFolio?.can as Partial<Record<Ability, boolean>> | undefined)?.[ability] ?? true;
}

/**
 * Whether this folder menu has anything in it for this person — the ⋮ on the
 * selected row and the narrow Folder actions control ask the same question.
 *
 * Every rail user can star, so in the library this is true for every role
 * (Nick, 23 Sep: the menu is for everyone, each action gated inside it, and
 * an action the role does not have is hidden rather than greyed). It is false
 * only where a bundle has withheld everything, as the gallery inspector does.
 */
export function hasFolderMenu(): boolean {
    return can('rename') || can('delete') || can('download') || can('star');
}
