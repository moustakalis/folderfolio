import { expect, test } from '@playwright/test';

import { createFolder, resetFolders, waitForTree } from './helpers/folders';

/**
 * Uploads follow the folder — screen 10's footer line, end to end.
 *
 * This is the one feature in the plugin whose failure mode is total silence.
 * Its predecessor, `upload-integration.ts`, shipped for the whole life of
 * 0.2.0 and the rebuild without ever filing a single upload: it watched a DOM
 * container that is rendered after the watcher is installed, so it bailed on
 * its first line, logged nothing, and looked exactly like a working feature
 * from the outside. Nothing but an upload that actually happens can tell the
 * difference, which is why this spec exists rather than a unit test of the
 * wiring.
 *
 * The file is built in the page and handed to plupload's own input, so the
 * request that reaches the server is the one a person's upload produces —
 * same uploader, same multipart parameters, same endpoint.
 */

/** A 2×2 PNG. Small enough to be free, real enough for WordPress to accept. */
const PNG =
    'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAF0lEQVQIHWNkYGD4z8DAwMgAAkAWGAAAIAUBAWtL1p0AAAAASUVORK5CYII=';

async function uploadThroughTheMediaGrid(page: import('@playwright/test').Page, name: string) {
    const input = page.locator('input[type="file"]').first();
    await input.waitFor({ state: 'attached' });

    await input.setInputFiles({
        name,
        mimeType: 'image/png',
        buffer: Buffer.from(PNG, 'base64'),
    });
}

/** What the plugin thinks is in a folder, asked through its own API. */
async function directCount(page: import('@playwright/test').Page, id: number): Promise<number> {
    return page.evaluate(async (folderId) => {
        const response = await window.wp.apiFetch({ path: '/folderfolio/v1/folders' });
        const find = (nodes: Array<Record<string, unknown>>): Record<string, unknown> | null => {
            for (const node of nodes) {
                if (node.id === folderId) {
                    return node;
                }

                const deeper = find(node.children as Array<Record<string, unknown>>);

                if (deeper) {
                    return deeper;
                }
            }

            return null;
        };

        return (find(response.data)?.count as number) ?? -1;
    }, id);
}

test.describe('uploading into the selected folder', () => {
    // php-wasm does an image upload and its thumbnails on two cores. The
    // suite's 90s budget is for page loads, not for that on top of them.
    test.setTimeout(180_000);

    test.beforeEach(async ({ page }) => {
        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);
        await page.reload();
        await page.locator('#folderfolio-rail').waitFor();
    });

    test('the folder on screen is the folder the upload request names', async ({ page }) => {
        const folder = await createFolder(page, 'Uploads target');
        await page.reload();
        await waitForTree(page, 'Uploads target');

        // Nothing selected: an upload here belongs to nobody, and the
        // parameter must say so rather than naming the last folder looked at.
        const beforeSelecting = await page.evaluate(
            () => window.wp?.Uploader?.defaults?.multipart_params?.folderfolio_folder ?? null
        );
        expect(beforeSelecting).toBeNull();

        await page.locator('.folderfolio-tree .folderfolio-row', { hasText: 'Uploads target' }).click();

        // Both seams, because neither covers the other: the defaults are what
        // a *new* uploader copies, and the live instance was built at page
        // load, before any folder existed to name.
        await expect
            .poll(() =>
                page.evaluate(
                    () => window.wp?.Uploader?.defaults?.multipart_params?.folderfolio_folder ?? null
                )
            )
            .toBe(String(folder.id));

        const onTheLiveUploader = await page.evaluate(() => {
            const uploader = window.wp?.media?.frame?.uploader?.uploader?.uploader;

            return uploader?.settings?.multipart_params?.folderfolio_folder ?? null;
        });
        expect(onTheLiveUploader).toBe(String(folder.id));

        expect(await directCount(page, folder.id)).toBe(0);

        await uploadThroughTheMediaGrid(page, 'ff-e2e-into-folder.png');

        await expect.poll(() => directCount(page, folder.id), { timeout: 90_000 }).toBe(1);
    });

    test('an upload with no folder selected is filed nowhere', async ({ page }) => {
        const folder = await createFolder(page, 'Not this one');
        await page.reload();
        await waitForTree(page, 'Not this one');

        const unassignedBefore = await page.evaluate(async () => {
            const response = await window.wp.apiFetch({ path: '/folderfolio/v1/counts' });

            return response.data.library.unassigned as number;
        });

        await uploadThroughTheMediaGrid(page, 'ff-e2e-unfiled.png');

        await expect
            .poll(
                () =>
                    page.evaluate(async () => {
                        const response = await window.wp.apiFetch({ path: '/folderfolio/v1/counts' });

                        return response.data.library.unassigned as number;
                    }),
                { timeout: 90_000 }
            )
            .toBe(unassignedBefore + 1);

        expect(await directCount(page, folder.id)).toBe(0);
    });
});
