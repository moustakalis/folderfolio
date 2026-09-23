import { Page } from '@playwright/test';

/**
 * Folders, created and removed through the plugin's own REST API.
 *
 * In the page rather than over HTTP from Node, because `wp.apiFetch` already
 * carries the session and the REST nonce — reproducing either outside the
 * browser would be testing the harness rather than the plugin.
 *
 * Every spec seeds what it needs and removes it again: the suite runs against
 * one WordPress with one worker, so a folder left behind is a folder the next
 * spec has to know about.
 */

/**
 * Wait for the tree to hold real folders.
 *
 * `.folderfolio-row` is not enough: while the tree query is in flight the rail
 * renders three ghost rows carrying exactly that class, so a wait on it is
 * satisfied by the skeleton and every assertion afterwards races the data.
 * Six of the first seventeen tests written here failed on precisely that.
 */
export async function waitForTree(page: Page, name?: string): Promise<void> {
    await page.locator('#folderfolio-rail').waitFor();

    if (name) {
        await page.locator('.folderfolio-tree .folderfolio-row', { hasText: name }).first().waitFor();

        return;
    }

    await page
        .locator('.folderfolio-tree .folderfolio-row:not(.folderfolio-row--ghost)')
        .first()
        .waitFor();
}

export interface SeededFolder {
    id: number;
    name: string;
}

export async function createFolder(
    page: Page,
    name: string,
    parentId: number | null = null,
    objectType = 'attachment'
): Promise<SeededFolder> {
    const id = await page.evaluate(
        async ([folderName, parent, type]) => {
            const response = await window.wp.apiFetch({
                path: '/folderfolio/v1/folders',
                method: 'POST',
                data: { name: folderName, parent_id: parent, object_type: type },
            });

            return response.data.id as number;
        },
        [name, parentId, objectType] as const
    );

    return { id, name };
}

/** Children are reparented, not cascaded — the same as the UI's own delete. */
export async function deleteFolders(page: Page, ids: number[]): Promise<void> {
    await page.evaluate(async (folderIds) => {
        for (const id of folderIds) {
            try {
                await window.wp.apiFetch({
                    path: `/folderfolio/v1/folders/${id}`,
                    method: 'DELETE',
                    data: { children: 'reparent' },
                });
            } catch {
                // Already gone: a spec that deleted it is a spec that passed.
            }
        }
    }, ids);
}

export async function folderNames(page: Page): Promise<string[]> {
    return page.evaluate(async () => {
        const response = await window.wp.apiFetch({ path: '/folderfolio/v1/folders' });

        const walk = (nodes: any[]): string[] =>
            nodes.flatMap((node) => [node.name, ...walk(node.children ?? [])]);

        return walk(response.data ?? []);
    });
}

/**
 * Remove every folder, so a spec starts from a library nobody has filed. One
 * type's tree at a time — media's unless told (tier 3 item 12).
 */
export async function resetFolders(page: Page, objectType = 'attachment'): Promise<void> {
    await page.evaluate(async (type) => {
        const response = await window.wp.apiFetch({ path: `/folderfolio/v1/folders?object_type=${type}` });

        for (const node of response.data ?? []) {
            await window.wp.apiFetch({
                path: `/folderfolio/v1/folders/${node.id}`,
                method: 'DELETE',
                data: { children: 'cascade' },
            });
        }
    }, objectType);
}

declare global {
    interface Window {
        wp: { apiFetch: (options: Record<string, unknown>) => Promise<any> };
    }
}
