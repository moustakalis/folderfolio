import { expect, test, type Page } from '@playwright/test';

import { createFolder, resetFolders } from './helpers/folders';

/**
 * Smart folders — tier 3 item 13, board KZsHhrffzKQYqUjTvdFszK (13a–c):
 * saved rules, every one must match, the site's (Organise), a Smart group
 * above the tree, never a drop target, media first. The rules themselves are
 * asserted against SQL in SmartFoldersTest; here is what a person does.
 */

async function resetSmart(page: Page): Promise<void> {
    await page.evaluate(async () => {
        const wp = (window as any).wp;
        const list = (await wp.apiFetch({ path: '/folderfolio/v1/smart' })).data as Array<{ id: number }>;

        for (const item of list) {
            await wp.apiFetch({ path: `/folderfolio/v1/smart/${item.id}`, method: 'DELETE' });
        }
    });
}

async function mediaIds(page: Page): Promise<number[]> {
    return page.evaluate(async () =>
        ((await (window as any).wp.apiFetch({ path: '/wp/v2/media?per_page=20&_fields=id' })) as Array<{ id: number }>).map((m) => m.id)
    );
}

const tiles = (page: Page) =>
    page.$$eval('.attachments-browser li.attachment[data-id]', (els) => els.map((el) => Number((el as HTMLElement).dataset.id)).sort());

test.describe('smart folders', () => {
    test.setTimeout(120_000);

    test('a smart folder is made from its rules, filters the library in place, and says what it is', async ({ page }) => {
        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        await resetSmart(page);
        await resetFolders(page);

        const all = await mediaIds(page);
        const filed = await createFolder(page, 'Filed already');
        await page.evaluate(
            async ([folder, id]) =>
                (window as any).wp.apiFetch({ path: '/folderfolio/v1/assignments', method: 'POST', data: { folder_id: folder, attachment_ids: [id] } }),
            [filed.id, all[0]] as const
        );

        try {
            await page.reload();
            const group = page.getByRole('group', { name: 'Smart folders' });
            await group.getByRole('button', { name: 'New smart folder' }).click();

            const dialog = page.getByRole('dialog', { name: 'New smart folder' });
            await expect(dialog).toBeVisible();
            await dialog.getByLabel('Name').fill('Unfiled images');
            // The default rules: an image, in no folder.
            await expect(dialog.getByLabel('Rule 1: about')).toHaveValue('type');
            await expect(dialog.getByLabel('Rule 2: how')).toHaveValue('none');
            await expect(dialog).toContainText(`Matches ${all.length - 1} files`);

            await page.evaluate(() => ((window as any).__ffSame = true));
            await dialog.getByRole('button', { name: 'Save' }).click();
            await expect(dialog).toBeHidden();

            // Selected, in place, and the grid is the rule's answer.
            const row = group.getByRole('button', { name: /^Unfiled images, \d+$/ });
            await expect(row).toHaveAttribute('aria-selected', 'true');
            await expect.poll(() => tiles(page)).toEqual(all.slice(1).sort());
            expect(await page.evaluate(() => (window as any).__ffSame)).toBe(true);
            const smartId = new URL(page.url()).searchParams.get('folderfolio_smart');
            expect(smartId).not.toBeNull();
            await expect(page.locator('.folderfolio-crumbs')).toContainText('Unfiled images');

            // Not a drop target: nothing in it says it takes a drop.
            await expect(row).not.toHaveAttribute('data-folderfolio-folder', /.*/);

            // A deep link comes back to it.
            await page.reload();
            await expect(group.getByRole('button', { name: /^Unfiled images/ })).toHaveAttribute('aria-selected', 'true');
            await expect.poll(() => tiles(page)).toEqual(all.slice(1).sort());

            // Clear filter is the way out, as for a folder.
            await page.locator('.folderfolio-crumbs').getByRole('button', { name: 'Clear filter' }).click();
            await expect.poll(() => tiles(page)).toEqual([...all].sort());
            expect(new URL(page.url()).searchParams.get('folderfolio_smart')).toBeNull();

            // Edit, then delete.
            await group.getByRole('button', { name: /^Unfiled images/ }).click();
            await group.getByRole('button', { name: 'Edit “Unfiled images”' }).click();
            const edit = page.getByRole('dialog', { name: 'Edit smart folder' });
            await edit.getByRole('button', { name: 'Delete' }).click();
            await edit.getByRole('button', { name: 'Delete' }).click();
            await expect(edit).toBeHidden();
            await expect(group.getByRole('button', { name: /^Unfiled images/ })).toHaveCount(0);
            await expect.poll(() => tiles(page)).toEqual([...all].sort());

            // A link to it now is a link to All media, as a deleted folder's is.
            await page.goto(`/wp-admin/upload.php?mode=grid&folderfolio_folder=&folderfolio_smart=${smartId}`);
            await expect.poll(() => new URL(page.url()).searchParams.get('folderfolio_smart')).toBeNull();
            await expect(page.locator('#folderfolio-rail').getByRole('button', { name: /^All media/ })).toHaveAttribute('aria-selected', 'true');
            await expect.poll(() => tiles(page)).toEqual([...all].sort());
        } finally {
            await resetSmart(page);
            await resetFolders(page);
        }
    });

    test('the list view filters by a smart folder too, and keeps it through a search', async ({ page }) => {
        await page.goto('/wp-admin/upload.php?mode=list');
        await page.locator('#folderfolio-rail').waitFor();
        await resetSmart(page);

        const all = await mediaIds(page);
        const id = await page.evaluate(
            async () =>
                (
                    await (window as any).wp.apiFetch({
                        path: '/folderfolio/v1/smart',
                        method: 'POST',
                        data: { name: 'Rig images', rules: [{ field: 'name', op: 'contains', value: 'rig-' }, { field: 'type', op: 'is', value: 'image' }] },
                    })
                ).data.id as number
        );

        try {
            await page.reload();
            await page.getByRole('group', { name: 'Smart folders' }).getByRole('button', { name: /^Rig images/ }).click();
            await expect(page.locator('input[type="hidden"][name="folderfolio_smart"]')).toHaveValue(String(id));
            await expect.poll(async () => page.locator('#the-list > tr[id^="post-"]').count()).toBe(all.length);
        } finally {
            await resetSmart(page);
        }
    });
});
