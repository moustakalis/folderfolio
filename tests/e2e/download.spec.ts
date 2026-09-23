import { execFileSync } from 'node:child_process';

import { expect, test, type Page } from '@playwright/test';

import { createFolder, resetFolders, waitForTree } from './helpers/folders';

/**
 * Download a folder as a ZIP — tier 2 item 11, boards PpiAmXsixk3sG9yygJQnw5
 * and XdRT8n1BYg2Pam9uWnLQ5y.
 *
 * The browser's own download is what is asserted: the ⋮ row starts one, the
 * file it saves is a ZIP holding the folder and its subfolders as directories,
 * a range of it is that range of the whole (so a browser's Resume works), and
 * above the size threshold the notice sheet says the size and asks first.
 */

async function seed(page: Page) {
    await page.goto('/wp-admin/upload.php?mode=grid');
    await page.locator('#folderfolio-rail').waitFor();
    await resetFolders(page);

    const kit = await createFolder(page, 'Kit');
    const web = await createFolder(page, 'Web', kit.id);
    await createFolder(page, 'Empty', kit.id);

    const files = await page.evaluate(async ([kitId, webId]) => {
        const media = await window.wp.apiFetch({ path: '/wp/v2/media?per_page=3&_fields=id,source_url' });
        const ids: number[] = media.map((m: { id: number }) => m.id);

        await window.wp.apiFetch({
            path: '/folderfolio/v1/attachments/assign',
            method: 'POST',
            data: { folder_id: kitId, attachment_ids: ids.slice(0, 2) },
        });
        await window.wp.apiFetch({
            path: '/folderfolio/v1/attachments/assign',
            method: 'POST',
            data: { folder_id: webId, attachment_ids: ids.slice(2) },
        });

        return media.map((m: { source_url: string }) => m.source_url.split('/').pop() as string);
    }, [kit.id, web.id] as const);

    await page.goto(`/wp-admin/upload.php?mode=grid&folderfolio_folder=${kit.id}`);
    await waitForTree(page, 'Kit');

    return { kit, web, files };
}

async function openKitMenu(page: Page) {
    await page.locator('.folderfolio-tree .folderfolio-row', { hasText: /^Kit/ }).first().click();
    await page.locator('.folderfolio-row__menu').click();
}

function entries(path: string): string[] {
    return execFileSync('unzip', ['-Z1', path], { encoding: 'utf8' }).trim().split('\n').sort();
}

test.describe('download a folder as ZIP', () => {
    test.afterEach(async ({ page }) => {
        await resetFolders(page);
    });

    test('the ⋮ row downloads the folder and its subfolders as directories', async ({ page }, info) => {
        const { files } = await seed(page);

        await openKitMenu(page);
        const started = page.waitForEvent('download');
        await page.getByRole('menuitem', { name: 'Download as ZIP' }).click();
        const download = await started;

        expect(download.suggestedFilename()).toBe('Kit.zip');

        const saved = info.outputPath('kit.zip');
        await download.saveAs(saved);

        // unzip -t: every entry's CRC checks out.
        expect(execFileSync('unzip', ['-tq', saved], { encoding: 'utf8' })).toContain('No errors detected');
        expect(entries(saved)).toEqual(
            ['Kit/', 'Kit/Empty/', 'Kit/Web/', `Kit/${files[0]}`, `Kit/${files[1]}`, `Kit/Web/${files[2]}`].sort()
        );
    });

    test('a range of the archive is that range of the whole, so a Resume works', async ({ page }) => {
        const { kit } = await seed(page);

        const url = await page.evaluate(async (id) => {
            const r = await window.wp.apiFetch({ path: `/folderfolio/v1/folders/${id}/zip` });

            return r.data.url as string;
        }, kit.id);

        const whole = await page.request.get(url);
        expect(whole.status()).toBe(200);
        expect(whole.headers()['accept-ranges']).toBe('bytes');

        const body = await whole.body();
        expect(Number(whole.headers()['content-length'])).toBe(body.length);

        const from = Math.floor(body.length / 2);
        const part = await page.request.get(url, {
            headers: { Range: `bytes=${from}-`, 'If-Range': whole.headers()['etag'] },
        });

        expect(part.status()).toBe(206);
        expect(part.headers()['content-range']).toBe(`bytes ${from}-${body.length - 1}/${body.length}`);
        expect(Buffer.compare(await part.body(), body.subarray(from))).toBe(0);

        // A link whose nonce is not this folder's is refused.
        const other = await page.request.get(url.replace(/folder=\d+/, `folder=${kit.id + 1}`));
        expect(other.status()).toBe(403);
    });

    test('above the threshold the sheet says the size and asks first', async ({ page }) => {
        await seed(page);

        // The server's answer, as a 3 GB folder would give it.
        await page.route('**/folderfolio/v1/folders/*/zip**', async (route) => {
            const response = await route.fetch();
            const json = await response.json();

            json.data.confirm = true;
            json.data.size = '3.2 GB';
            json.data.files = 1284;

            await route.fulfill({ response, json });
        });

        await openKitMenu(page);
        await page.getByRole('menuitem', { name: 'Download as ZIP' }).click();

        const sheet = page.locator('.folderfolio-toast--notice');
        await expect(sheet).toContainText('“Kit” is 3.2 GB in 1,284 files');

        const started = page.waitForEvent('download');
        await sheet.getByRole('button', { name: 'Download' }).click();
        expect((await started).suggestedFilename()).toBe('Kit.zip');
        await expect(sheet).toHaveCount(0);
    });

    test('an Author has Download beside Star', async ({ page }) => {
        await seed(page);

        await page.evaluate(() => {
            (window as unknown as { folderFolio: { can: Record<string, boolean> } }).folderFolio.can = {
                create: true,
                rename: false,
                delete: false,
                assign: true,
                lock: false,
                download: true,
            };
        });

        await openKitMenu(page);
        const menu = page.locator('.folderfolio-menu--row');

        await expect(menu.locator('[role^="menuitem"]')).toHaveCount(2);
        await expect(menu.getByRole('menuitemcheckbox', { name: 'Star' })).toBeVisible();
        await expect(menu.getByRole('menuitem', { name: 'Download as ZIP' })).toBeVisible();
    });
});
