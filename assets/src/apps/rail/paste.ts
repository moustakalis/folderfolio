/**
 * Cut, copy and paste — tier 1 item 5.
 *
 * Two halves, like move.ts. `planPaste()` is pure: given the tree as the
 * person sees it, what is held, and where they asked for it, it answers with
 * the one request that does it — or null, which is the whole of "you cannot
 * paste that here". The menu asks the same function to decide whether a row is
 * enabled, so an item that is enabled and a paste that does nothing cannot
 * disagree. `usePaste()` is the other half: the clipboard, the three requests,
 * and what the screen does afterwards, shared by the menu and the keyboard.
 *
 * ## Why a paste is three different requests
 *
 * - **Cut into a level in Custom order** is a reorder: the level with the
 *   folder placed in it, which is what a drag sends and what folds the move
 *   into the same transaction. The position is visible there, so it is chosen.
 * - **Cut into any other level** is a plain move. The folder takes that
 *   level's sort like everything else in it, and the level's own arrangement —
 *   which the person is not looking at — is left exactly as it was. Sending a
 *   reorder here would quietly rewrite a Custom order they made last week into
 *   whatever Name, A to Z happens to show today.
 * - **Copy** is a duplicate, with the level sent along only when it is in
 *   Custom order and the copy is going *beside* something — the one case where
 *   "where" means more than "which folder".
 */

import { useCallback, useMemo } from 'react';

import { isSortOrder, useRail, type Clipboard, type SortOrder } from './store';
import { useDuplicateFolder, useMoveFolder, useReorderFolders, type FolderNode } from './queries';
import { can } from '../../lib/can';
import { t } from '../../core/api';

export type Where = 'inside' | 'beside';

export type PastePlan =
    | { kind: 'move'; id: number; parentId: number | null }
    | { kind: 'reorder'; parentId: number | null; ids: number[] }
    | {
          kind: 'duplicate';
          id: number;
          parentId: number | null;
          withFiles: boolean;
          order?: number[];
      };

interface Node {
    id: number;
    sort_folders?: string | null;
    children: Node[];
}

interface Found<T> {
    node: T;
    parent: T | null;
    siblings: T[];
    depth: number;
}

function find<T extends Node>(nodes: T[], id: number, parent: T | null = null, depth = 0): Found<T> | null {
    for (const node of nodes) {
        if (node.id === id) {
            return { node, parent, siblings: nodes, depth };
        }

        const hit = find(node.children as T[], id, node, depth + 1);

        if (hit) {
            return hit;
        }
    }

    return null;
}

/** How many levels sit below a folder: 0 for a folder with no children. */
function height(node: Node): number {
    return node.children.reduce((deepest, child) => Math.max(deepest, 1 + height(child)), 0);
}

function contains(node: Node, id: number): boolean {
    return node.id === id || node.children.some((child) => contains(child, id));
}

/**
 * The deepest a folder may sit, as the server will judge it.
 *
 * `folderfolio_max_depth`, filtered, handed over in the app config. The same
 * comparison `FolderService::guardDepth()` makes — a root is depth 0 — so a
 * paste the menu offers is not one the server refuses on depth.
 */
function maxDepth(): number {
    return window.folderFolio?.maxDepth ?? 20;
}

/**
 * The one request a paste is, or null when it cannot be done here.
 *
 * `roots` must be the tree **as sorted on screen**, for the reason move.ts
 * gives: "beside this folder" means next to the row the person is looking at.
 */
export function planPaste<T extends Node>(
    roots: T[],
    held: Clipboard,
    targetId: number,
    where: Where,
    global: SortOrder
): PastePlan | null {
    const source = find(roots, held.id);
    const target = find(roots, targetId);

    if (!source || !target) {
        return null;
    }

    const destination = where === 'inside' ? target.node : target.parent;
    const destinationDepth = where === 'inside' ? target.depth + 1 : target.depth;
    const level = where === 'inside' ? target.node.children : target.siblings;
    const parentId = destination ? destination.id : null;

    // Inside itself, or inside anything beneath it. The server refuses this
    // too; asking here is what draws the row disabled instead of letting it
    // be pressed.
    if (destination && contains(source.node, destination.id)) {
        return null;
    }

    if (destinationDepth + height(source.node) > maxDepth()) {
        return null;
    }

    const levelSort: SortOrder =
        destination && isSortOrder(destination.sort_folders) ? destination.sort_folders : global;
    const custom = levelSort === 'custom';

    if (held.verb === 'cut') {
        const alreadyHere = (source.parent ? source.parent.id : null) === parentId;

        if (!custom) {
            // Already in this level and no position to choose: nothing to do.
            return alreadyHere ? null : { kind: 'move', id: held.id, parentId };
        }

        // Beside itself is where it already is.
        if (where === 'beside' && targetId === held.id) {
            return null;
        }

        const ids = level.map((node) => node.id).filter((id) => id !== held.id);

        if (where === 'inside') {
            ids.push(held.id);
        } else {
            ids.splice(ids.indexOf(targetId) + 1, 0, held.id);
        }

        return { kind: 'reorder', parentId, ids };
    }

    let order: number[] | undefined;

    if (custom && where === 'beside') {
        order = level.map((node) => node.id);
        order.splice(order.indexOf(targetId) + 1, 0, 0);
    }

    return {
        kind: 'duplicate',
        id: held.id,
        parentId,
        withFiles: held.withFiles,
        ...(order ? { order } : {}),
    };
}

/**
 * Is what is held still in the tree?
 *
 * A folder can be deleted — here, or by someone else — while it waits on the
 * clipboard. Its paste rows then have nothing to paste, and a label naming it
 * would be offering a folder that is gone.
 */
export function stillThere<T extends Node>(roots: T[], held: Clipboard): boolean {
    return find(roots, held.id) !== null;
}

/** May this person act on what is held, at all? The abilities half. */
export function mayPaste(held: Clipboard): boolean {
    if (held.verb === 'cut') {
        return can('rename');
    }

    return can('create') && can('rename') && (!held.withFiles || can('assign'));
}

/** The line above the two paste rows, naming what they will paste. */
export function heldLabel(held: Clipboard): string {
    if (held.verb === 'cut') {
        return t('pasteHeldCut', 'Paste “%s” · cut', held.name);
    }

    return held.withFiles
        ? t('pasteHeldCopyFiles', 'Paste “%s” · with files', held.name)
        : t('pasteHeldCopy', 'Paste “%s” · copy', held.name);
}

/**
 * The clipboard's actions, for the menu and for the keyboard alike.
 */
export function usePaste() {
    const hold = useRail((s) => s.hold);
    const expand = useRail((s) => s.expand);
    const duplicate = useDuplicateFolder();
    const move = useMoveFolder();
    const reorder = useReorderFolders();

    const take = useCallback(
        (node: FolderNode, verb: Clipboard['verb'], withFiles = false) => {
            hold({ id: node.id, name: node.name, verb, withFiles: verb === 'copy' && withFiles });

            window.wp?.a11y?.speak(
                verb === 'cut'
                    ? t('cutAnnounce', 'Cut “%s”. Paste it inside or beside another folder.', node.name)
                    : t('copyAnnounce', 'Copied “%s”. Paste it inside or beside another folder.', node.name),
                'polite'
            );
        },
        [hold]
    );

    const paste = useCallback(
        (roots: FolderNode[], targetId: number, where: Where): boolean => {
            const held = useRail.getState().clipboard;

            if (!held || !mayPaste(held)) {
                return false;
            }

            const plan = planPaste(roots, held, targetId, where, useRail.getState().sort);

            if (!plan) {
                return false;
            }

            const done = () => {
                // A cut is spent by its paste; a copy stays held, so a second
                // paste makes "Brand copy 2" — the file-manager convention.
                if (held.verb === 'cut') {
                    hold(null);
                }

                // Pasted inside a folder that was closed: open it, or the
                // result is off screen and the paste looks like it did nothing.
                if (where === 'inside') {
                    expand(targetId);
                }

                window.wp?.a11y?.speak(t('pasted', 'Pasted “%s”', held.name), 'polite');
            };

            /*
             * `mutateAsync` and its promise, not `mutate` with callbacks.
             *
             * The menu closes the moment a paste row is pressed, and the menu
             * is what called this hook — so by the time the server answers,
             * the component that owns these mutations has unmounted, and
             * TanStack Query drops the callbacks passed to `mutate()` for an
             * unmounted observer without a word. The first build did exactly
             * that: the folder moved, and the destination never opened. The
             * promise belongs to the mutation, not the observer.
             */
            const request: Promise<unknown> =
                plan.kind === 'move'
                    ? move.mutateAsync({ id: plan.id, parentId: plan.parentId })
                    : plan.kind === 'reorder'
                      ? reorder.mutateAsync({ parentId: plan.parentId, ids: plan.ids })
                      : duplicate.mutateAsync(plan);

            // A refusal is shown by the rail's MutationCache, the same way
            // every other write's is (rail.tsx) — so nothing to do here but
            // keep the rejection from surfacing as an unhandled promise.
            request.then(done, () => undefined);

            return true;
        },
        [hold, expand, duplicate, move, reorder]
    );

    // Memoised, because Tree puts this in its key handler's dependencies and
    // a new object every render would rebuild that handler for nothing.
    return useMemo(() => ({ take, paste, clear: () => hold(null) }), [take, paste, hold]);
}
