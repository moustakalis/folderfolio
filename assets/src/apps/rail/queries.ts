/**
 * Server data for the rail.
 *
 * Two queries, because they answer two different questions and change at
 * different times: the shape of the tree, and how many files the library holds
 * outside it. Keeping them apart means creating a folder refetches the tree
 * without re-counting the whole library.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { apiFetch, ApiEnvelope } from '../../core/api';
import type { Swatch } from '../../lib/swatches';

export interface FolderNode {
    id: number;
    parent_id: number | null;
    name: string;
    /**
     * A swatch name — 'steel', 'plum' — or null. Never a hex: the hex a
     * swatch resolves to belongs to the admin colour scheme. See
     * lib/swatches.ts. Typed as a plain string rather than Swatch because
     * this is what came off the wire, and swatchStyle() is what decides
     * whether it is one of the ten.
     */
    color: string | null;
    depth: number;
    path: string;
    children: FolderNode[];
    /** Files filed in this folder itself. */
    count: number;
    /** Files anywhere in its subtree — what the badge shows by default. */
    total_count: number;
    /**
     * Where the folder sits among its siblings.
     *
     * Data, not a view preference: `sort` in store.ts is what the user
     * happens to be looking at, and this is the arrangement underneath it.
     * Cast to an int by FolderTree::fromRows, because $wpdb would otherwise
     * hand it over as a string and "10" sorts before "9".
     */
    sort_order: number;
    /**
     * How this folder shows what is inside it, or null for "follow whatever
     * the person is looking at".
     *
     * Plain strings, not `SortOrder`: they came off the wire. `isSortOrder`
     * in store.ts is what narrows them, and a value it does not recognise
     * falls back to the global order rather than leaving a level unsorted.
     *
     * `sort_files` is applied server-side — see MediaLibraryFilter — so the
     * client only ever reads it to show which one is ticked.
     */
    sort_folders: string | null;
    sort_files: string | null;
}

export interface LibraryCounts {
    all: number;
    unassigned: number;
}

export const treeKey = ['folderfolio', 'tree'] as const;
export const countsKey = ['folderfolio', 'counts'] as const;

export function useTree() {
    return useQuery({
        queryKey: treeKey,
        queryFn: async (): Promise<FolderNode[]> => {
            const response = await apiFetch<ApiEnvelope<FolderNode[]>>(
                // No ?counts=: the mode is a site setting now, and the
                // server applies it. Sending 'inherited' from here made the
                // rail the one place on the site that ignored it.
                '/folders'
            );

            return response.data ?? [];
        },
        // The tree is small and changes only when this user changes it, so
        // there is no point re-fetching it on every window focus. Mutations
        // invalidate it explicitly.
        staleTime: Infinity,
        refetchOnWindowFocus: false,
    });
}

export function useLibraryCounts() {
    return useQuery({
        queryKey: countsKey,
        queryFn: async (): Promise<LibraryCounts> => {
            const response = await apiFetch<ApiEnvelope<{ library: LibraryCounts }>>('/counts');

            return response.data.library;
        },
        staleTime: Infinity,
        refetchOnWindowFocus: false,
    });
}

/**
 * Renaming. Optimistic, unlike creating.
 *
 * The id already exists and only one field changes, so the new name can be put
 * in the cache immediately and rolled back if the request fails. That is the
 * difference the handoff means by "optimistic everything" — it applies where
 * the client already knows the answer.
 */
export function useRenameFolder() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: async (input: { id: number; name: string }) => {
            const response = await apiFetch<ApiEnvelope<FolderNode>>(`/folders/${input.id}`, {
                method: 'POST',
                data: { name: input.name },
            });

            return response.data;
        },

        onMutate: async (input) => {
            await client.cancelQueries({ queryKey: treeKey });

            const previous = client.getQueryData<FolderNode[]>(treeKey);

            client.setQueryData<FolderNode[]>(treeKey, (nodes) =>
                mapTree(nodes ?? [], (node) =>
                    node.id === input.id ? { ...node, name: input.name } : node
                )
            );

            return { previous };
        },

        onError: (_error, _input, context) => {
            if (context?.previous) {
                client.setQueryData(treeKey, context.previous);
            }
        },

        onSettled: () => {
            void client.invalidateQueries({ queryKey: treeKey });
        },
    });
}

/**
 * Setting a folder's colour, from the More menu's swatch picker.
 *
 * Optimistic, for the same reason renaming is: the id exists, one field
 * changes, and the client already knows the answer. It matters more here than
 * it does for a rename — the picker is a grid of ten dots and the thing it
 * changes is a 16px icon three inches away, so a round trip between the click
 * and the colour would read as the click not having registered.
 *
 * `color` is a swatch name and never a hex. See lib/swatches.ts.
 */
/**
 * How one folder shows what is inside it.
 *
 * Two scopes, one route. The folder half is applied by `sortTree`, so its
 * optimistic write is the whole of the change the person sees; the file half
 * is applied server-side by `MediaLibraryFilter`, so the optimistic write
 * only moves the tick and the library catches up when it next queries.
 *
 * Invalidates the tree and nothing else. Counts do not move, and the library
 * is WordPress's own query rather than one of ours to invalidate.
 */
export function useSetFolderSort() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: async (input: {
            id: number;
            scope: 'folders' | 'files';
            order: string | null;
        }) => {
            const response = await apiFetch<ApiEnvelope<{ tree: FolderNode[] }>>(
                `/folders/${input.id}/sort`,
                {
                    method: 'POST',
                    // '' and not null, for the reason the colour mutation
                    // gives: the REST field is typed, and the server reads an
                    // empty string as "clear it".
                    data: { scope: input.scope, order: input.order ?? '' },
                }
            );

            return response.data;
        },

        onMutate: async (input) => {
            await client.cancelQueries({ queryKey: treeKey });

            const previous = client.getQueryData<FolderNode[]>(treeKey);
            const field = input.scope === 'files' ? 'sort_files' : 'sort_folders';

            client.setQueryData<FolderNode[]>(treeKey, (nodes) =>
                mapTree(nodes ?? [], (node) =>
                    node.id === input.id ? { ...node, [field]: input.order } : node
                )
            );

            return { previous };
        },

        onError: (_error, _input, context) => {
            if (context?.previous) {
                client.setQueryData(treeKey, context.previous);
            }
        },

        onSettled: () => {
            void client.invalidateQueries({ queryKey: treeKey });
        },
    });
}

export function useSetFolderColor() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: async (input: { id: number; color: Swatch | null }) => {
            const response = await apiFetch<ApiEnvelope<FolderNode>>(`/folders/${input.id}`, {
                method: 'POST',
                // '' and not null: the REST field is typed string, and a null
                // would be rejected by the schema before FolderService could
                // read it as "clear this". The server maps an empty string to
                // NULL in the column.
                data: { color: input.color ?? '' },
            });

            return response.data;
        },

        onMutate: async (input) => {
            await client.cancelQueries({ queryKey: treeKey });

            const previous = client.getQueryData<FolderNode[]>(treeKey);

            client.setQueryData<FolderNode[]>(treeKey, (nodes) =>
                mapTree(nodes ?? [], (node) =>
                    node.id === input.id ? { ...node, color: input.color } : node
                )
            );

            return { previous };
        },

        onError: (_error, _input, context) => {
            if (context?.previous) {
                client.setQueryData(treeKey, context.previous);
            }
        },

        onSettled: () => {
            void client.invalidateQueries({ queryKey: treeKey });
        },
    });
}

/**
 * Deleting, with the 5-second window the design promises.
 *
 * The server is not told until the window closes. The row disappears at once —
 * which is what "acts immediately" means to the person looking at it — but
 * undo is then simply not making the call, rather than trying to put a folder
 * back together afterwards.
 *
 * Recreating is the alternative, and it is worse: the delete takes the
 * folder's id with it, and its id is what every attachment assignment,
 * breadcrumb and bookmarked URL refers to. A "restored" folder with a new id
 * would be a different folder wearing the same name.
 *
 * The cost is that a delete can be lost — closing the tab inside the window
 * leaves the folder there if the flush does not make it. That is the right way
 * round: a delete not happening is an inconvenience, a folder disappearing
 * when it should not have is data loss.
 */
export function useDeleteFolder() {
    const client = useQueryClient();

    const commit = async (id: number) => {
        await apiFetch<ApiEnvelope<unknown>>(`/folders/${id}`, {
            method: 'DELETE',
            // Children move up rather than going with it. Cascade is a much
            // bigger action than the one word "Delete" on a toolbar button
            // implies, and the toast has no room to explain it.
            data: { children: 'reparent' },
        });
    };

    return {
        /** Take the folder out of the tree now and hand back the committer. */
        remove(id: number): { commit: () => Promise<void>; restore: () => void } {
            const previous = client.getQueryData<FolderNode[]>(treeKey);

            client.setQueryData<FolderNode[]>(treeKey, (nodes) => pruneTree(nodes ?? [], id));

            return {
                commit: async () => {
                    try {
                        await commit(id);
                    } finally {
                        void client.invalidateQueries({ queryKey: treeKey });
                        void client.invalidateQueries({ queryKey: countsKey });
                    }
                },
                restore: () => {
                    if (previous) {
                        client.setQueryData(treeKey, previous);
                    } else {
                        void client.invalidateQueries({ queryKey: treeKey });
                    }
                },
            };
        },
    };
}

/**
 * What a drop does.
 *
 * Two endpoints behind one intent, chosen by whether there is a folder to move
 * out of — see drag.ts for why that is one rule and not two behaviours.
 *
 * Not optimistic. The counts that would have to be adjusted are subtree totals
 * all the way up two different branches, and the library itself re-queries
 * from the server anyway; guessing the numbers here would mean two sources for
 * one figure, which is the thing the tree badge is supposed to stop doing.
 */
export function useMoveAttachments() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: async (input: {
            ids: number[];
            sourceFolderId: number | null;
            destinationFolderId: number;
        }) => {
            if (input.sourceFolderId !== null) {
                await apiFetch<ApiEnvelope<unknown>>('/attachments/bulk-move', {
                    method: 'POST',
                    data: {
                        source_folder_id: input.sourceFolderId,
                        destination_folder_id: input.destinationFolderId,
                        attachment_ids: input.ids,
                    },
                });

                return;
            }

            // No source: add a membership, and leave every other one alone.
            await apiFetch<ApiEnvelope<unknown>>('/attachments/assign', {
                method: 'POST',
                data: {
                    folder_id: input.destinationFolderId,
                    attachment_ids: input.ids,
                    mode: 'add',
                },
            });
        },

        onSuccess: () => {
            void client.invalidateQueries({ queryKey: treeKey });
            void client.invalidateQueries({ queryKey: countsKey });

            // The library is showing a folder whose contents just changed.
            // Nothing else would tell it.
            window.dispatchEvent(new CustomEvent('folderfolio:library-changed'));
        },
    });
}

/**
 * Arranging a level, and the cross-parent drag that is the same gesture.
 *
 * Optimistic, and it has to be: the folder is under the pointer when it is
 * dropped, so a round trip between the drop and the row landing would read as
 * the drop not having taken. Unlike a rename there is more than one field to
 * guess, but all of them are ones the client already knows — which folders,
 * in which order, under which parent.
 *
 * `depth` and `path` are deliberately left alone on a folder that changes
 * parent. They are derived columns the server rewrites for the whole subtree,
 * and nothing on screen reads them: both Tree and flattenTree compute indent
 * from the nesting they are walking. Guessing them here would put a second,
 * wrong answer in the cache until the invalidate lands.
 */
export function useReorderFolders() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: async (input: { parentId: number | null; ids: number[] }) => {
            const response = await apiFetch<ApiEnvelope<{ arranged: number }>>(
                '/folders/reorder',
                {
                    method: 'POST',
                    data: { parent_id: input.parentId, ids: input.ids },
                }
            );

            return response.data;
        },

        onMutate: async (input) => {
            await client.cancelQueries({ queryKey: treeKey });

            const previous = client.getQueryData<FolderNode[]>(treeKey);

            client.setQueryData<FolderNode[]>(treeKey, (nodes) =>
                reorderLevel(nodes ?? [], input.parentId, input.ids)
            );

            return { previous };
        },

        onError: (_error, _input, context) => {
            if (context?.previous) {
                client.setQueryData(treeKey, context.previous);
            }
        },

        // The tree only. A reorder moves no files, so the library counts are
        // the same numbers — but a folder that changed parent changes two
        // subtree totals, and those live on the tree nodes.
        onSettled: () => {
            void client.invalidateQueries({ queryKey: treeKey });
        },
    });
}

/**
 * A copy of the tree with one level arranged into the given order.
 *
 * Lift, then place. Every folder named in `ids` comes out of wherever it
 * currently sits — which is the whole operation for a reorder, and also
 * handles the one arriving from another parent without a second code path.
 */
function reorderLevel(
    nodes: FolderNode[],
    parentId: number | null,
    ids: number[]
): FolderNode[] {
    const wanted = new Set(ids);
    const lifted = new Map<number, FolderNode>();

    const lift = (level: FolderNode[]): FolderNode[] =>
        level.flatMap((node) => {
            const kept = { ...node, children: lift(node.children) };

            if (wanted.has(node.id)) {
                lifted.set(node.id, kept);

                return [];
            }

            return [kept];
        });

    const rest = lift(nodes);

    const arranged = ids.flatMap((id, index) => {
        const node = lifted.get(id);

        return node ? [{ ...node, parent_id: parentId, sort_order: index }] : [];
    });

    if (parentId === null) {
        // The server rejects a list that does not describe the whole level,
        // so `rest` holds no other roots by the time this runs. Appending
        // rather than assuming that keeps a rejected call from losing rows
        // before the rollback puts them back.
        return [...arranged, ...rest];
    }

    const place = (level: FolderNode[]): FolderNode[] =>
        level.map((node) =>
            node.id === parentId
                ? { ...node, children: arranged }
                : { ...node, children: place(node.children) }
        );

    return place(rest);
}

/** A copy of the tree with one node replaced wherever it appears. */
function mapTree(nodes: FolderNode[], fn: (node: FolderNode) => FolderNode): FolderNode[] {
    return nodes.map((node) => ({ ...fn(node), children: mapTree(node.children, fn) }));
}

/**
 * A copy of the tree without one node — its children taking its place, which
 * is what `children: reparent` does on the server.
 */
function pruneTree(nodes: FolderNode[], id: number): FolderNode[] {
    return nodes.flatMap((node) =>
        node.id === id
            ? pruneTree(node.children, id)
            : [{ ...node, children: pruneTree(node.children, id) }]
    );
}

export function useCreateFolder() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: async (input: { name: string; parentId: number | null }) => {
            const response = await apiFetch<ApiEnvelope<FolderNode>>('/folders', {
                method: 'POST',
                data: { name: input.name, parent_id: input.parentId },
            });

            return response.data;
        },
        // Not optimistic. The server assigns the id, and the id is what the
        // new row has to be keyed, selected and revealed by — so inventing one
        // here would mean reconciling it a moment later. Creating a folder is
        // also deliberate and rare; the wait is a spinner's worth, not a
        // dropped drag.
        onSuccess: () => {
            void client.invalidateQueries({ queryKey: treeKey });
        },
    });
}

/**
 * Cut + paste into a level that is not in Custom order — tier 1 item 5.
 *
 * Just the move: the folder takes the destination's sort like everything
 * else there, and the level's own arrangement is left exactly as it was. A
 * paste into a Custom level goes through `useReorderFolders` instead, because
 * there a position is something the person can see and chose.
 *
 * Not optimistic, like creating: a paste is deliberate and rare, and the
 * reorder path already carries the optimistic version for the case where the
 * position is visible.
 */
export function useMoveFolder() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: async (input: { id: number; parentId: number | null }) => {
            const response = await apiFetch<ApiEnvelope<{ tree: FolderNode[] }>>(
                `/folders/${input.id}/move`,
                { method: 'POST', data: { parent_id: input.parentId } }
            );

            return response.data;
        },
        onSettled: () => {
            void client.invalidateQueries({ queryKey: treeKey });
        },
    });
}

/**
 * Copy + paste — a new subtree, and with it, perhaps, a second filing of
 * every file inside it.
 *
 * `order` is the destination level as the person sees it with a `0` where the
 * copy goes, sent only when that level is in Custom order; the server puts the
 * real id in its place inside the same transaction.
 *
 * With files, the library's counts are invalidated as well as the tree: no
 * file changes state between filed and unfiled, so today they come back the
 * same — but "the counts cannot have changed" is a claim about the server this
 * would have to keep true for ever, and one refetch is cheaper than that.
 */
export function useDuplicateFolder() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: async (input: {
            id: number;
            parentId: number | null;
            withFiles: boolean;
            order?: number[];
        }) => {
            const response = await apiFetch<ApiEnvelope<{ folder: FolderNode }>>(
                `/folders/${input.id}/duplicate`,
                {
                    method: 'POST',
                    data: {
                        parent_id: input.parentId,
                        with_files: input.withFiles,
                        ...(input.order ? { order: input.order } : {}),
                    },
                }
            );

            return response.data.folder;
        },
        onSettled: (_data, _error, input) => {
            void client.invalidateQueries({ queryKey: treeKey });

            if (input.withFiles) {
                void client.invalidateQueries({ queryKey: countsKey });
            }
        },
    });
}

/**
 * The bulk "Add to folder" flyout — screens 03, 06 and 11.
 *
 * Adds, and only adds. The membership model is many-to-many, so filing a file
 * into Campaigns is not a statement about whether it is also in Brand, and the
 * flyout has no way to show what it would be taking away. Removing is the
 * drag's job, where the folder you are dragging out of is on screen and named.
 *
 * One request per destination, run in sequence rather than in parallel: they
 * all write the same assignments table, and a handful of folders is not a
 * queue worth flooding for a saving nobody would see. Sequential also means a
 * failure halfway leaves a knowable state — the folders before it are done —
 * instead of an arbitrary subset.
 */
export function useAddToFolders() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: async (input: { ids: number[]; folderIds: number[] }) => {
            for (const folderId of input.folderIds) {
                await apiFetch<ApiEnvelope<{ assigned: number }>>('/attachments/assign', {
                    method: 'POST',
                    data: {
                        folder_id: folderId,
                        attachment_ids: input.ids,
                        // Explicit, though it is also the server's default:
                        // this is the one property of this mutation that the
                        // design names, and it should not be legible only by
                        // knowing what FolderService::MODE_ADD happens to be.
                        mode: 'add',
                    },
                });
            }

            return input;
        },

        onSuccess: () => {
            void client.invalidateQueries({ queryKey: treeKey });
            void client.invalidateQueries({ queryKey: countsKey });

            // The Folders column in list mode, and the folder the grid is
            // filtered to, both just became stale.
            window.dispatchEvent(new CustomEvent('folderfolio:library-changed'));
        },
    });
}

/**
 * The tree as a flat list, deepest indent preserved.
 *
 * Both of this step's controls are flat lists of folders — a `<select>` cannot
 * nest, and the flyout's checkbox rows are one scrollable column — so both
 * need the tree's shape expressed as a number rather than as nesting.
 */
export interface FlatFolder {
    node: FolderNode;
    depth: number;
}

export function flattenTree(nodes: FolderNode[], depth = 0, out: FlatFolder[] = []): FlatFolder[] {
    for (const node of nodes) {
        out.push({ node, depth });
        flattenTree(node.children, depth + 1, out);
    }

    return out;
}

/** A folder that matched a search, with the ancestor names above it. */
export interface FolderHit {
    node: FolderNode;
    /** Ancestor names, root first — what the second line of a result shows. */
    trail: string[];
}

/**
 * Every folder whose name contains `needle`, carrying its path.
 *
 * Shared by the rail's own results list and by the narrow-width folder picker,
 * so that typing the same three letters in two places on one screen cannot
 * produce two different answers.
 *
 * Always recurses, match or not: a matching folder inside a non-matching
 * parent is exactly the case a tree *filter* gets wrong, and it is the common
 * case once a library is deep.
 */
export function searchTree(
    nodes: FolderNode[],
    needle: string,
    trail: string[] = [],
    out: FolderHit[] = []
): FolderHit[] {
    for (const node of nodes) {
        if (node.name.toLowerCase().includes(needle)) {
            out.push({ node, trail });
        }

        searchTree(node.children, needle, [...trail, node.name], out);
    }

    return out;
}
