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

    select: (id: number | null) => void;
    focus: (id: number | null) => void;
    toggle: (id: number) => void;
    expand: (id: number) => void;
    collapse: (id: number) => void;
    /** Open every ancestor of a folder, so a deep link can reveal its row. */
    reveal: (ancestorIds: number[]) => void;
    setQuery: (query: string) => void;
}

export const useRail = create<RailState>((set) => ({
    selectedId: folderFromUrl(),
    focusedId: folderFromUrl(),
    expandedIds: new Set<number>(),
    query: '',

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
}));
