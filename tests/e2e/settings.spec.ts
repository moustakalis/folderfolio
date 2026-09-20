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


    /**
     * Finding 02 of the settings audit.
     *
     * The roles matrix is the only table on either of the plugin's screens,
     * and a table has an intrinsic minimum it will not go below. It floored at
     * 361.8px against a 318px content box at a 390px viewport, and
     * `.folderfolio-matrix-wrap` is `overflow-x: visible`, so there was no
     * scroller to absorb it and the *page* scrolled sideways instead: 7px at
     * 390, 37px at 360, 77px at 320.
     *
     * Two assertions, because either alone passes on a broken screen.
     * `scrollWidth === clientWidth` alone is satisfied by an overflow smaller
     * than the padding around it — which is exactly how this was first
     * mis-measured as fixed, with the table still 4.2px past the card's own
     * border at 320. And a table-inside-card check alone says nothing about
     * anything else on the page.
     *
     * The precondition matters too: an empty table fits everything. The column
     * count is asserted so this cannot pass on a matrix that failed to render.
     */
    test('the roles matrix fits its card at every width, and the page never scrolls sideways', async ({
        page,
    }) => {
        await page.goto(`${SETTINGS}&tab=settings`);
        await page.locator('.folderfolio-matrix').waitFor();

        type Row = {
            width: number;
            columns: number;
            roles: number;
            table: number;
            content: number;
            overContent: number;
            pastBorder: number;
            pageOverflow: number;
            headFont: string;
        };

        const rows: Row[] = [];

        // 783/782 is where wp-admin drops the admin menu; 521/520 is our own
        // breakpoint; the rest are real phones. 1280 is the control: nothing
        // below 520 should reach it.
        for (const width of [1280, 960, 783, 782, 521, 520, 480, 390, 375, 360, 320]) {
            await page.setViewportSize({ width, height: 900 });
            // Let the media query and the table's own layout settle.
            await page.waitForTimeout(250);

            rows.push(
                await page.evaluate((w) => {
                    const matrix = document.querySelector('.folderfolio-matrix') as HTMLElement;
                    const card = document.querySelector('.folderfolio-settings') as HTMLElement;
                    const body = document.querySelector(
                        '.folderfolio-settings__body'
                    ) as HTMLElement;
                    const de = document.documentElement;
                    const cs = getComputedStyle(body);

                    // clientWidth INCLUDES padding, so it is the wrong box to
                    // compare a child against.
                    const content =
                        body.clientWidth
                        - parseFloat(cs.paddingLeft)
                        - parseFloat(cs.paddingRight);

                    const mb = matrix.getBoundingClientRect();
                    const cb = card.getBoundingClientRect();

                    return {
                        width: w,
                        columns: matrix.querySelectorAll('thead th').length,
                        roles: matrix.querySelectorAll('tbody tr').length,
                        table: Math.round(mb.width * 10) / 10,
                        content: Math.round(content * 10) / 10,
                        overContent: Math.round((mb.width - content) * 10) / 10,
                        pastBorder: Math.round((mb.right - cb.right) * 10) / 10,
                        pageOverflow: de.scrollWidth - de.clientWidth,
                        headFont: getComputedStyle(
                            matrix.querySelector('thead th') as HTMLElement
                        ).fontSize,
                    };
                }, width)
            );
        }

        console.table(rows);

        // The precondition: five roles against four abilities, plus the role
        // column. An empty table would satisfy everything below.
        for (const row of rows) {
            expect(row.columns, `${row.width}px: the matrix did not render its heads`).toBe(5);
            expect(row.roles, `${row.width}px: the matrix did not render its roles`).toBe(5);
        }

        // The defect, at every width.
        for (const row of rows) {
            expect(
                row.pageOverflow,
                `${row.width}px: the page scrolls sideways by ${row.pageOverflow}px`
            ).toBe(0);

            expect(
                row.overContent,
                `${row.width}px: the table is ${row.table}px in a ${row.content}px content box`
            ).toBeLessThanOrEqual(0.5);

            expect(
                row.pastBorder,
                `${row.width}px: the table reaches past the card's own border`
            ).toBeLessThan(0);
        }

        // The breakpoint fires where the stylesheet says, and nowhere else.
        const at = (w: number) => rows.find((r) => r.width === w)!;

        expect(at(1280).headFont, 'the wide metrics are untouched').toBe('11px');
        expect(at(521).headFont, '521 is above the breakpoint').toBe('11px');
        expect(at(520).headFont, '520 is where the narrow metrics start').toBe('10px');
        expect(at(320).headFont).toBe('10px');
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
