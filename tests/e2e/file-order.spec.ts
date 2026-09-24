import { expect, test, type Page } from '@playwright/test';

import { createFolder, resetFolders } from './helpers/folders';

/**
 * Manual file order inside a folder — tier 2 item 8.
 *
 * Nick's answers (board 66d5HKiPtdtNYTf7JWWBG5): files are placed by a drag
 * between tiles and by Move to start / Move to end; placing a file makes the
 * folder Custom; a new file goes first.
 *
 * The drag itself is not asserted here, for the reason rail.spec.ts gives: a
 * green drag test would be testing Playwright's synthesiser. Its contract is
 * `planTileDrop()` (tests/js) and the route both it and the buttons send to.
 * What is asserted is everything a person sees either way — and the order the
 * grid shows, which the first version of this feature got wrong for every
 * folder order, Custom or not: core's collection re-sorted each page by date
 * in the browser.
 */

interface Fixture {
    folder: number;
    /** Newest first, as the library opens a folder with no order: d c b a. */
    ids: number[];
    titles: Record<number, string>;
}

async function fixture(page: Page): Promise<Fixture> {
    await page.goto('/wp-admin/upload.php?mode=grid');
    await page.locator('#folderfolio-rail').waitFor();
    await resetFolders(page);

    const folder = await createFolder(page, 'Arranged');

    return page.evaluate(async (folderId) => {
        const media = await window.wp.apiFetch({
            path: '/wp/v2/media?per_page=4&orderby=date&order=desc&_fields=id,title',
        });
        const ids: number[] = media.map((m: { id: number }) => m.id);
        const titles: Record<number, string> = {};

        // Titles against the dates — d is the newest — so name order and date
        // order disagree, and the original titles kept to put back.
        for (const [i, id] of ids.entries()) {
            titles[id] = media[i].title.rendered;
            await window.wp.apiFetch({
                path: `/wp/v2/media/${id}`,
                method: 'POST',
                data: { title: `order-${'dcba'[i]}` },
            });
        }

        await window.wp.apiFetch({
            path: '/folderfolio/v1/assignments',
            method: 'POST',
            data: { folder_id: folderId, attachment_ids: ids },
        });

        return { folder: folderId, ids, titles };
    }, folder.id);
}

async function restore(page: Page, fx: Fixture): Promise<void> {
    await page.evaluate(async (titles) => {
        for (const [id, title] of Object.entries(titles)) {
            await window.wp.apiFetch({ path: `/wp/v2/media/${id}`, method: 'POST', data: { title } });
        }
    }, fx.titles);
    await resetFolders(page);
}

const gridOrder = (page: Page) =>
    page.$$eval('.attachments-browser li.attachment[data-id]', (tiles) =>
        tiles.map((tile) => Number((tile as HTMLElement).dataset.id))
    );

const listOrder = (page: Page) =>
    page.$$eval('#the-list tr[id^="post-"]', (rows) => rows.map((row) => Number(row.id.slice(5))));

async function openGrid(page: Page, folder: number, count = 4): Promise<void> {
    await page.goto(`/wp-admin/upload.php?mode=grid&folderfolio_folder=${folder}`);
    await expect(page.locator('.attachments-browser li.attachment[data-id]')).toHaveCount(count);
}

test.describe('manual file order inside a folder', () => {
    let fx: Fixture;

    test.beforeEach(async ({ page }) => {
        fx = await fixture(page);
    });

    test.afterEach(async ({ page }) => {
        await restore(page, fx);
    });

    test('the grid shows a folder in its own file order, not re-sorted by date', async ({ page }) => {
        const [d, c, b, a] = fx.ids;

        await openGrid(page, fx.folder);
        expect(await gridOrder(page)).toEqual([d, c, b, a]);

        await page.evaluate(
            (folder) =>
                window.wp.apiFetch({
                    path: `/folderfolio/v1/folders/${folder}/sort`,
                    method: 'POST',
                    data: { scope: 'files', order: 'name-asc' },
                }),
            fx.folder
        );

        await openGrid(page, fx.folder);
        await expect.poll(() => gridOrder(page)).toEqual([a, b, c, d]);
    });

    test('Move to start and Move to end, from the grid, make the folder Custom', async ({ page }) => {
        const [d, c, b, a] = fx.ids;

        await openGrid(page, fx.folder);

        await page.getByRole('button', { name: 'Bulk select' }).click();
        await page.locator(`.attachments-browser li.attachment[data-id="${b}"]`).click();
        await page.locator(`.attachments-browser li.attachment[data-id="${a}"]`).click();

        await page.getByRole('button', { name: /add to folder/i }).click();

        const arrange = page.getByRole('group', { name: 'In Arranged' });
        await expect(arrange).toBeVisible();
        await arrange.getByRole('button', { name: 'Move to start' }).click();

        // b and a, in the order they already had, ahead of the rest.
        await expect.poll(() => gridOrder(page)).toEqual([b, a, d, c]);

        // The folder is Custom now, and says so where its order is chosen.
        const sortFiles = await page.evaluate(
            async (folder) =>
                (await window.wp.apiFetch({ path: '/folderfolio/v1/folders?counts=none' })).data.find(
                    (node: { id: number }) => node.id === folder
                ).sort_files,
            fx.folder
        );
        expect(sortFiles).toBe('custom');

        // And it survives a reload — the server's order, not the page's.
        await openGrid(page, fx.folder);
        expect(await gridOrder(page)).toEqual([b, a, d, c]);
    });

    test('Move to end from the list view', async ({ page }) => {
        const [d, c, b, a] = fx.ids;

        await page.goto(`/wp-admin/upload.php?mode=list&folderfolio_folder=${fx.folder}`);
        expect(await listOrder(page)).toEqual([d, c, b, a]);

        await page.locator(`#cb-select-${d}`).check();
        await page.getByRole('button', { name: /add to folder/i }).click();
        await page
            .getByRole('group', { name: 'In Arranged' })
            .getByRole('button', { name: 'Move to end' })
            .click();

        await expect.poll(() => listOrder(page)).toEqual([c, b, a, d]);
    });

    test('a column the person clicks still sorts the list, over the folder’s own order', async ({ page }) => {
        const [d, c, b, a] = fx.ids;

        await page.evaluate(
            ({ folder, ids }) =>
                window.wp.apiFetch({
                    path: `/folderfolio/v1/folders/${folder}/files/order`,
                    method: 'POST',
                    data: { ids, place: 'start' },
                }),
            { folder: fx.folder, ids: [b] }
        );

        await page.goto(`/wp-admin/upload.php?mode=list&folderfolio_folder=${fx.folder}`);
        expect(await listOrder(page)).toEqual([b, d, c, a]);

        await page.goto(`/wp-admin/upload.php?mode=list&folderfolio_folder=${fx.folder}&orderby=title&order=asc`);
        expect(await listOrder(page)).toEqual([a, b, c, d]);
    });

    test('there is nothing to arrange outside a folder', async ({ page }) => {
        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        await page.locator('.attachments-browser li.attachment[data-id]').first().waitFor();

        await page.getByRole('button', { name: 'Bulk select' }).click();
        await page.locator('.attachments-browser li.attachment[data-id]').first().click();
        await page.getByRole('button', { name: /add to folder/i }).click();

        await expect(page.locator('.folderfolio-flyout')).toBeVisible();
        await expect(page.locator('.folderfolio-flyout__arrange')).toHaveCount(0);
    });
});
