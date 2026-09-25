import assert from 'node:assert/strict';
import { afterEach, describe, it } from 'node:test';

import { QueryClient } from '@tanstack/react-query';

import { pendingDeletes, treeKey, withoutPending, writeTree, type FolderNode } from '../../../assets/src/apps/rail/queries';
import { f } from './tree';

const names = (nodes: FolderNode[] | undefined): string[] =>
    (nodes ?? []).flatMap((n) => [n.name, ...names(n.children)]);

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

describe('the tree cache', () => {
    afterEach(() => pendingDeletes.clear());

    /**
     * Review M9: a folder in its undo window stays out of every tree that
     * reaches the cache, including one fetched before the server was told.
     */
    it('keeps a folder whose delete is pending out of a fetched tree', () => {
        const tree = [f(1, 'A'), f(2, 'B', [f(3, 'B child')])] as unknown as FolderNode[];

        pendingDeletes.add(2);

        // Pruned as the server's reparent would: the child takes its place.
        assert.deepEqual(names(withoutPending(tree)), ['A', 'B child']);
    });

    /**
     * Review L15: a write's answer is not overwritten by an older refetch
     * that was already in flight.
     */
    it('a write answered with the tree wins over an older fetch in flight', async () => {
        const client = new QueryClient();
        const stale = [f(1, 'Unlocked')] as unknown as FolderNode[];
        const fresh = [f(1, 'Locked', [], { locked: true, locked_by: 1 })] as unknown as FolderNode[];

        client.setQueryData(treeKey, stale);

        // A refetch started before the write, answering after it.
        const inFlight = client
            .fetchQuery({ queryKey: treeKey, queryFn: () => sleep(30).then(() => stale), staleTime: 0 })
            .catch(() => undefined);

        await sleep(5);
        await writeTree(client, fresh);
        await inFlight;
        await sleep(40);

        assert.deepEqual(names(client.getQueryData<FolderNode[]>(treeKey)), ['Locked']);
        client.clear();
    });
});
