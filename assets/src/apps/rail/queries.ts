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
