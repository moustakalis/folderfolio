/**
 * The rail's own state.
 *
 * Everything here is interface state — what is open, what is focused, what has
 * been typed. Server data lives in TanStack Query (see queries.ts) and is
 * deliberately not duplicated into this store: two copies of the folder tree
 * is how a count ends up disagreeing with the row above it.
 *
 * Zustand rather than context: the tree re-renders on every arrow-key press,
 * and a context holding this would re-render every consumer each time. A
 * selector subscribes a component to one field.
 */

import { create } from 'zustand';

import { folderFromUrl } from '../../lib/filter';

export interface RailState {
    /**
     * `null` = All media, `0` = Unassigned, otherwise a folder id.
     *
     * The same three-way value the URL and MediaLibraryFilter use — see
     * lib/filter.ts. Seeded from the URL so a deep link, a refresh, or a
     * bookmarked folder all come back to the right row.
     */
    selectedId: number | null;

    /**
     * Which row the keyboard is on.
     *
     * Tracked apart from selection on purpose: the handoff is explicit that
     * arrowing through the tree must not re-filter the library. Moving focus
     * is free; selecting is a query.
     */
    focusedId: number | null;

    expandedIds: Set<number>;

    /** Non-empty switches the rail from the tree to a flat result list. */
    query: string;

    /**
     * The one row that is currently an input.
     *
     * Creating and renaming are the same mechanic — an input where a row's
     * name would be — so they are one piece of state. Two would allow both at
     * once, which is a state nobody designed and the tree would have to
     * render.
     */
    editing: Editing | null;

    /**
     * How the tree is ordered. Applied client-side to each level.
     *
     * A view preference, not data: the folders keep their own sort_order for
     * manual arrangement, and this is what the user happens to be looking at.
     */
    sort: SortOrder;

    /**
     * A delete the user can still take back.
     *
     * The row is gone from the tree the moment it is deleted, but the server
     * has not been told yet — see useDeleteFolder. `deadline` is a timestamp
     * rather than a remaining duration so that pausing on hover is a matter of
     * moving it, not of running a second clock.
     */
    pendingUndo: PendingUndo | null;

    select: (id: number | null) => void;
    focus: (id: number | null) => void;
    toggle: (id: number) => void;
    expand: (id: number) => void;
    collapse: (id: number) => void;
    /** Open every ancestor of a folder, so a deep link can reveal its row. */
    reveal: (ancestorIds: number[]) => void;
    setQuery: (query: string) => void;

    edit: (editing: Editing | null) => void;
    setEditValue: (value: string) => void;
    setSort: (sort: SortOrder) => void;
    setPendingUndo: (undo: PendingUndo | null) => void;
}

export type SortOrder = 'name-asc' | 'name-desc' | 'newest' | 'oldest';

export interface Editing {
    mode: 'create' | 'rename';
    /** Where a new folder will go. `null` is the top level. */
    parentId: number | null;
    /** Which folder is being renamed. Unused when creating. */
    folderId: number | null;
    value: string;
}

export interface PendingUndo {
    folderId: number;
    name: string;
    /** Files that will be left unassigned — what the toast promises. */
    fileCount: number;
    /** When the delete becomes permanent, in ms. Moved forward while hovered. */
    deadline: number;
}

export const useRail = create<RailState>((set) => ({
    selectedId: folderFromUrl(),
    focusedId: folderFromUrl(),
    expandedIds: new Set<number>(),
    query: '',
    editing: null,
    sort: 'name-asc',
    pendingUndo: null,

    select: (id) => set({ selectedId: id, focusedId: id }),
    focus: (id) => set({ focusedId: id }),

    // A new Set each time rather than mutating: Zustand compares by reference,
    // and a mutated Set is the same reference, so nothing would re-render.
    toggle: (id) =>
        set((state) => {
            const next = new Set(state.expandedIds);

            if (!next.delete(id)) {
                next.add(id);
            }

            return { expandedIds: next };
        }),

    expand: (id) =>
        set((state) =>
            state.expandedIds.has(id)
                ? state
                : { expandedIds: new Set(state.expandedIds).add(id) }
        ),

    collapse: (id) =>
        set((state) => {
            if (!state.expandedIds.has(id)) {
                return state;
            }

            const next = new Set(state.expandedIds);
            next.delete(id);

            return { expandedIds: next };
        }),

    reveal: (ancestorIds) =>
        set((state) => {
            const next = new Set(state.expandedIds);
            ancestorIds.forEach((id) => next.add(id));

            return { expandedIds: next };
        }),

    setQuery: (query) => set({ query }),

    edit: (editing) => set({ editing }),
    setEditValue: (value) =>
        set((state) => (state.editing ? { editing: { ...state.editing, value } } : state)),
    setSort: (sort) => set({ sort }),
    setPendingUndo: (pendingUndo) => set({ pendingUndo }),
}));

/**
 * The tree, in the order the user asked for.
 *
 * Applied at render rather than in the query, so changing the order costs
 * nothing and never refetches. Recursive, because a sort that only reordered
 * the top level would be a strange half-measure.
 */
export function sortTree<T extends { name: string; id: number; children: T[] }>(
    nodes: T[],
    order: SortOrder
): T[] {
    const sorted = [...nodes].sort((a, b) => {
        switch (order) {
            case 'name-desc':
                return b.name.localeCompare(a.name, undefined, { numeric: true });

            // No created_at on the node type, and adding one to sort by would
            // mean trusting a string date from the server. Ids are assigned in
            // creation order by the database, which is the same answer.
            case 'newest':
                return b.id - a.id;

            case 'oldest':
                return a.id - b.id;

            default:
                return a.name.localeCompare(b.name, undefined, { numeric: true });
        }
    });

    return sorted.map((node) => ({ ...node, children: sortTree(node.children, order) }));
}
