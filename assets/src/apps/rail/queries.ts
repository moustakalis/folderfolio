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

export interface FolderNode {
    id: number;
    parent_id: number | null;
    name: string;
    color: string | null;
    depth: number;
    path: string;
    children: FolderNode[];
    /** Files filed in this folder itself. */
    count: number;
    /** Files anywhere in its subtree — what the badge shows by default. */
    total_count: number;
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
                '/folders?counts=inherited'
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
