/**
 * The wizard's four REST calls.
 *
 * Deliberately thin. Every decision the import makes — what merges, what is
 * skipped, when it is finished — is made on the server and arrives here as
 * data. A wizard that recomputed any of it in the browser would be a second
 * implementation free to disagree with the first, and the one people would
 * believe is the one on screen.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { apiFetch, type ApiEnvelope } from '../../core/api';

export interface DetectedSource {
    key: string;
    label: string;
    has_data: boolean;
    plugin_active: boolean;
    folders: number;
    assignments: number;
}

export interface RunState {
    id: string;
    source: string;
    label: string;
    status: 'pending' | 'running' | 'stopping' | 'done' | 'undone';
    cursor: number;
    total: number;
    progress: number;
    folders_created: number;
    folders_merged: number;
    duplicates_collapsed: number;
    files_added: number;
    skipped: number[];
    skipped_total: number;
    unreachable: Array<{ id: number; name: string; reason: string }>;
    error: string;
    can_undo: boolean;
}

export interface PlanCounts {
    create: number;
    merge: number;
    reconcile: number;
    duplicate: number;
    files: number;
    already: number;
    skipped: number;
    unreachable: number;
}

export interface PlanState {
    source: string;
    label: string;
    counts: PlanCounts;
    samples: {
        create: string[];
        merge: string[];
        reconcile: string[];
        duplicate: string[];
        skipped: string[];
        unreachable: string[];
    };
}

const sourcesKey = ['folderfolio', 'import', 'sources'] as const;

export function useSources() {
    return useQuery({
        queryKey: sourcesKey,
        queryFn: async () => {
            const response = await apiFetch<
                ApiEnvelope<{ sources: DetectedSource[]; run: RunState | null }>
            >('/import/sources');

            return response.data;
        },
    });
}

export function usePlan(source: string | null) {
    return useQuery({
        queryKey: ['folderfolio', 'import', 'plan', source],
        // Only when a source has been chosen: previewing is a read, but it is
        // a read that walks the whole source tree, and nine of them on page
        // load would be nine of those.
        enabled: source !== null,
        // A preview is a promise about right now. Re-reading it when the user
        // comes back to the tab is the difference between "this is what will
        // happen" and "this is what would have happened when you opened the
        // page".
        staleTime: 0,
        queryFn: async () => {
            const response = await apiFetch<ApiEnvelope<{ plan: PlanState }>>(
                `/import/${source}/plan`
            );

            return response.data.plan;
        },
    });
}

/**
 * Start, step, stop, undo — four calls that all answer with the same run.
 *
 * One hook rather than four, because the caller's job is identical in every
 * case: replace what it knows about the run with what the server just said.
 */
export function useRunAction() {
    const client = useQueryClient();

    return useMutation({
        mutationFn: async (action: 'run' | 'stop' | 'undo' | `${string}/start`) => {
            const response = await apiFetch<ApiEnvelope<{ run: RunState }>>(
                `/import/${action}`,
                { method: 'POST' }
            );

            return response.data.run;
        },
        onSuccess: () => {
            // The folder tree in the library behind this screen is now wrong,
            // and so is the source list's idea of what is already imported.
            void client.invalidateQueries({ queryKey: sourcesKey });
        },
    });
}
