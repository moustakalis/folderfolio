import { expect, test, type Page } from '@playwright/test';

import { createFolder, resetFolders, waitForTree } from './helpers/folders';

/**
 * The settings screen — screen 08.
 *
 * Three tabs and a form, which a unit test covers well enough on its own. What
 * it cannot cover is the half that makes the screen worth having: that saving
 * a value here changes what the media library does on the next page load. Both
 * of the tests that matter below cross that line.
 */

const SETTINGS = '/wp-admin/admin.php?page=folderfolio';

/**
 * Put the form back the way it shipped.
 *
 * There is no REST route for settings and no reason to add one for a form
 * posted twice in a site's lifetime, so the reset goes through the form — which
 * has the side benefit of exercising the save path in every spec that uses it.
 */
/**
 * Pick one of the two count modes.
 *
 * By its label, not its input. The segmented control's radios are visually
 * hidden — the label is what a user sees and clicks, and the input sits
 * underneath one of them, so clicking the input directly is both unlike the
 * user and ambiguous about which segment was hit.
 */
async function chooseCount(page: Page, label: string): Promise<void> {
    await page.locator('.folderfolio-seg label', { hasText: label }).click();
}

async function resetSettings(page: Page): Promise<void> {
    await page.goto(`${SETTINGS}&tab=settings`);

    await chooseCount(page, 'Inherited');
    await page.getByLabel('Default sort').selectOption('name-asc');
    await page.getByLabel('Undo window').fill('5');
    await page.getByRole('checkbox', { name: 'Create — Author' }).check();

    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByText('Settings saved.')).toBeVisible();
}

test.describe('the settings screen', () => {
    test.afterEach(async ({ page }) => {
        await resetSettings(page);
    });

    test('is one page with three tabs, landing on Settings', async ({ page }) => {
        await page.goto(SETTINGS);

        const tabs = page.locator('.folderfolio-settings__tabs a');

        await expect(tabs).toHaveText(['Settings', 'Import', 'Status']);
        await expect(tabs.nth(0)).toHaveAttribute('aria-current', 'page');

        // The tabs are links, not buttons: each one is a URL, so a bookmark
        // and a middle-click both work.
        await expect(tabs.nth(2)).toHaveAttribute('href', /tab=status/);
    });

    test('the administrator row is pinned, and stays ticked across a save', async ({ page }) => {
        await page.goto(SETTINGS);

        const create = page.getByRole('checkbox', { name: 'Create — Administrator' });

        await expect(create).toBeChecked();
        await expect(create).toBeDisabled();

        // Disabled inputs post nothing. Without the hidden field beside each
        // one, the first save would drop the administrator row from the
        // matrix — which is the one row nobody can restore from the admin.
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(page.getByText('Settings saved.')).toBeVisible();

        await expect(page.getByRole('checkbox', { name: 'Delete — Administrator' })).toBeChecked();
    });

    test('the count setting changes what the library counts', async ({ page }) => {
        // Eight page loads against a WordPress running on php-wasm: seeding a
        // folder tree and a file, reading the badge, changing the setting,
        // reading it again, and clearing up after itself. The default 30s is
        // enough on its own and not enough behind twenty other tests.
        test.setTimeout(120_000);

        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);

        const parent = await createFolder(page, 'Brand');
        const child = await createFolder(page, 'Logos', parent.id);

        // One file, filed in the child only. Inherited says the parent holds
        // it; direct says it does not. That disagreement is the whole setting.
        //
        // The file is created here rather than assumed: the harness boots a
        // fresh WordPress, and a fresh WordPress has an empty media library.
        const attachmentId = await page.evaluate(async (folderId) => {
            // A 1x1 transparent PNG — the smallest thing the media library
            // will accept and generate no thumbnails worth waiting for.
            const bytes = Uint8Array.from(
                atob(
                    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
                ),
                (c) => c.charCodeAt(0)
            );

            const form = new FormData();
            form.append('file', new File([bytes], 'folderfolio-count.png', { type: 'image/png' }));

            const media = await window.wp.apiFetch({
                path: '/wp/v2/media',
                method: 'POST',
                body: form,
            });

            await window.wp.apiFetch({
                path: '/folderfolio/v1/attachments/assign',
                method: 'POST',
                data: { folder_id: folderId, attachment_ids: [media.id] },
            });

            return media.id as number;
        }, child.id);

        const parentBadge = async (): Promise<string> =>
            (
                (await page
                    .locator('.folderfolio-tree .folderfolio-row', { hasText: 'Brand' })
                    .first()
                    .locator('.folderfolio-row__count')
                    .textContent()) ?? ''
            ).trim();

        await page.reload();
        await waitForTree(page, 'Brand');
        expect(await parentBadge()).toBe('1');

        await page.goto(`${SETTINGS}&tab=settings`);
        await chooseCount(page, 'Direct only');
        await expect(page.getByRole('radio', { name: 'Direct only' })).toBeChecked();
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(page.getByText('Settings saved.')).toBeVisible();

        await page.goto('/wp-admin/upload.php?mode=grid');
        await waitForTree(page, 'Brand');
        expect(await parentBadge()).toBe('0');

        // Clean up from a page that has finished loading. Running the two
        // cleanup evaluates straight after the assertion raced the grid's own
        // startup once in a full-suite run, and an evaluate whose execution
        // context is destroyed mid-flight fails the test for a reason that has
        // nothing to do with the setting.
        await page.goto('/wp-admin/upload.php');
        await page.locator('#folderfolio-rail').waitFor();

        await page.evaluate(
            async (id) =>
                window.wp.apiFetch({ path: `/wp/v2/media/${id}?force=true`, method: 'DELETE' }),
            attachmentId
        );
        await resetFolders(page);
    });

    test('the default sort setting is the sort the rail opens on', async ({ page }) => {
        await page.goto(`${SETTINGS}&tab=settings`);
        await page.getByLabel('Default sort').selectOption('name-desc');
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(page.getByText('Settings saved.')).toBeVisible();

        await page.goto('/wp-admin/upload.php?mode=grid');

        // The rail, not the tree: the sort menu is in the toolbar and does not
        // need a folder to exist, and the spec before this one leaves none.
        await page.locator('#folderfolio-rail').waitFor();

        await page.getByRole('button', { name: 'Sort' }).click();

        await expect(
            page.getByRole('menuitemradio', { name: 'Name, Z to A' })
        ).toHaveAttribute('aria-checked', 'true');
    });

    test('Status reports the schema and offers the report as text', async ({ page }) => {
        await page.goto(`${SETTINGS}&tab=status`);

        await expect(page.locator('.folderfolio-status')).toContainText('Schema version');
        await expect(page.locator('.folderfolio-status')).toContainText(
            'In step with the adjacency list'
        );

        // The report is in a textarea rather than behind the copy button
        // alone, so it can still be selected by hand with scripts off.
        await expect(page.locator('#folderfolio-report')).toHaveValue(/FolderFolio \d/);

        await expect(page.getByRole('button', { name: 'Rebuild paths' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Remove orphaned rows' })).toBeVisible();
    });
});
