/**
 * Moving a folder one place among its siblings.
 *
 * The maths behind three gestures that are the same operation: Alt+Arrow in
 * the tree, Move up / Move down in the toolbar's More menu, and — at a
 * distance — a drop, which is this with a free choice of position rather than
 * a step of one. It lives here because the toolbar is drawn once for both
 * renderers while the tree is drawn for one of them, and a second copy of a
 * splice is a second place for an off-by-one to live.
 *
 * It takes the *sorted* roots, not what came off the wire. "Move up" means
 * "above the row above me", and which row that is depends on the sort the
 * person is looking at. Under Custom the two agree; under Name, A to Z they
 * do not, and the order on screen is the one they meant.
 *
 * Returning null is the whole of "you cannot do that": the folder is not in
 * this tree, or it is already at the end it is being sent to. The toolbar
 * asks the same question to decide whether to disable the item, so an item
 * that is enabled and an action that does nothing cannot disagree.
 */

interface Node {
    id: number;
    children: Node[];
}

export interface SiblingMove {
    parentId: number | null;
    /** The whole level, in its new order — what POST /folders/reorder wants. */
    ids: number[];
}

export function planSiblingMove<T extends Node>(
    roots: T[],
    id: number,
    direction: -1 | 1
): SiblingMove | null {
    const level = levelOf(roots, id, null);

    if (!level) {
        return null;
    }

    const from = level.siblings.findIndex((s) => s.id === id);
    const to = from + direction;

    if (from === -1 || to < 0 || to >= level.siblings.length) {
        return null;
    }

    const ids = level.siblings.map((s) => s.id);
    ids.splice(to, 0, ids.splice(from, 1)[0]);

    return { parentId: level.parent ? level.parent.id : null, ids };
}

/**
 * The level a folder sits on, and what it sits under.
 *
 * `siblings` is the array the folder is actually in — the roots themselves
 * for a top-level folder, which is why the parent is reported separately and
 * as null rather than inferred from an empty list.
 */
function levelOf<T extends Node>(
    roots: T[],
    id: number,
    parent: T | null
): { parent: T | null; siblings: T[] } | null {
    for (const node of roots) {
        if (node.id === id) {
            return { parent, siblings: roots };
        }

        const found = levelOf(node.children as T[], id, node);

        if (found) {
            return found;
        }
    }

    return null;
}
