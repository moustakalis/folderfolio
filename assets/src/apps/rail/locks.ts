/**
 * Whether a lock stops this person — tier 2 item 10.
 *
 * A lock covers the folder and everything beneath it; the server says which
 * folder's lock covers a node (`locked_by`, `FolderTree::withMarks()`), and
 * those who hold the `lock` ability are not stopped by it (Nick's answers,
 * board 3ZU8VGkJemznTvKp8tNnvY). One function, so the ⋮ menu, the keyboard,
 * the drag and the paste rows cannot disagree about it — and the server asks
 * the same question again (`FolderLocks::guard()`), so this is the
 * affordance and that is the rule.
 */

import { can } from '../../lib/can';

export interface Lockable {
    id: number;
    name: string;
    locked_by?: number | null;
    children: Lockable[];
}

/** True when a lock covers this folder and this person may not pass it. */
export function isBlocked(node: { locked_by?: number | null } | null | undefined): boolean {
    return node?.locked_by != null && !can('lock');
}

/** The name of the folder whose lock covers `node`, for the menu's sentence. */
export function lockingName(nodes: readonly Lockable[], node: { locked_by?: number | null }): string {
    const id = node.locked_by;

    if (id == null) {
        return '';
    }

    const walk = (level: readonly Lockable[]): string | null => {
        for (const candidate of level) {
            if (candidate.id === id) {
                return candidate.name;
            }

            const deeper = walk(candidate.children);

            if (deeper !== null) {
                return deeper;
            }
        }

        return null;
    };

    return walk(nodes) ?? '';
}

/**
 * A lock stops this person somewhere beneath `node` — a delete would take it
 * with it (a cascade) or move it (a reparent), so the delete is refused.
 */
export function hasLockInside(node: { children: readonly { locked?: boolean; children: readonly unknown[] }[] }): boolean {
    if (can('lock')) {
        return false;
    }

    const walk = (children: readonly { locked?: boolean; children: readonly unknown[] }[]): boolean =>
        children.some(
            (child) =>
                Boolean(child.locked) ||
                walk(child.children as readonly { locked?: boolean; children: readonly unknown[] }[])
        );

    return walk(node.children);
}
