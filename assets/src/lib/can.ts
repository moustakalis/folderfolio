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

export type Ability = 'create' | 'rename' | 'delete' | 'assign';

export function can(ability: Ability): boolean {
    return window.folderFolio?.can?.[ability] ?? true;
}
