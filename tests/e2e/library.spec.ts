import { expect, test } from '@playwright/test';

import { createFolder, resetFolders, waitForTree } from './helpers/folders';

/**
 * What FolderFolio adds to WordPress's own library chrome — the filter-row
 * select, the bulk controls, and the Folders column in list mode.
 *
 * All three live in markup core owns and rebuilds underneath us, which is the
 * thing most likely to break on a WordPress upgrade and the thing a unit test
 * cannot see.
 */

test.describe('the library toolbar', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);
        await createFolder(page, 'Brand');
        await page.reload();
        await waitForTree(page, 'Brand');
    });

    test('puts the folder select in the frame toolbar, after the date filter', async ({ page }) => {
        const select = page.locator('#folderfolio-folder-filter');
        await expect(select).toBeVisible();

        // Order matters: screen 03 puts it after the date filter and before
        // Bulk select, and core hides the whole row in select mode.
        const after = await page.evaluate(
            () =>
                document.querySelector('.folderfolio-slot--filter')?.previousElementSibling?.id
        );
        expect(after).toBe('media-attachment-date-filters');

        await expect(select.locator('option')).toContainText(['All media', 'Unassigned', 'Brand']);
    });

    test('offers both bulk verbs, each disabled until it can do anything', async ({ page }) => {
        const add = page.getByRole('button', { name: /add to folder/i });
        const move = page.getByRole('button', { name: /move to folder/i });

        await expect(add).toBeDisabled();
        await expect(move).toBeDisabled();

        // A move needs a folder to move out of, so it says so rather than
        // sitting there greyed with no explanation.
        await expect(move).toHaveAttribute('title', /move needs a folder/i);
    });

    test('keeps the bulk controls when core rebuilds the toolbar', async ({ page }) => {
        // Entering Bulk select re-renders the media frame's toolbar and hides
        // every child of it but three. The slot has to survive that, and the
        // Add-to-folder control has to stay visible while the filters go.
        await page.getByRole('button', { name: /bulk select/i }).click();

        await expect(page.getByRole('button', { name: /add to folder/i })).toBeVisible();
        await expect(page.locator('#folderfolio-folder-filter')).toBeHidden();
    });
});

test.describe('list mode', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/wp-admin/upload.php?mode=list');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);
        await createFolder(page, 'Brand');
        await page.reload();
        await waitForTree(page, 'Brand');
    });

    test('adds exactly one column, between File and Author', async ({ page }) => {
        const headers = await page
            .locator('.wp-list-table thead th')
            .evaluateAll((els) => els.map((el) => el.className));

        const folders = headers.findIndex((c) => c.includes('column-folderfolio_folders'));

        expect(folders).toBeGreaterThan(-1);
        // The File column carries sorting classes too, so match on the column
        // class rather than on the last word of the list.
        expect(headers[folders - 1]).toContain('column-title');
    });

    test('prints the folder select into core filter bar, inside the posts form', async ({ page }) => {
        // restrict_manage_posts fires with $which === 'bar' on this table, and
        // the bar is NOT inside .tablenav.top. Getting that wrong puts the
        // control below the table.
        const where = await page.evaluate(() => {
            const select = document.querySelector('select[name="folderfolio_folder"]');

            return {
                inFilterBar: !!select?.closest('.wp-filter .actions'),
                inPostsForm: select?.closest('form')?.id ?? null,
                inTablenav: !!select?.closest('.tablenav'),
            };
        });

        expect(where).toEqual({
            inFilterBar: true,
            inPostsForm: 'posts-filter',
            inTablenav: false,
        });
    });

    test('has exactly one control carrying the folder query var', async ({ page }) => {
        // Two controls with one name in one GET form means the browser submits
        // both, and PHP keeps whichever came last.
        await expect(page.locator('#posts-filter [name="folderfolio_folder"]')).toHaveCount(1);
    });
});

test.describe('the media picker', () => {
    test('puts a folder column beside the attachments, at the cramped geometry', async ({ page }) => {
        await page.goto('/wp-admin/edit.php?post_type=post');

        await page.evaluate(() => {
            const frame = (window as any).wp.media({ title: 'Select', multiple: false });
            (window as any).__frame = frame;
            frame.open();
        });

        await page.locator('.media-modal').waitFor();

        // A library with nothing in it opens on Upload files, so the
        // attachments browser — which the folder column is a sibling of — is
        // not rendered until the Media Library tab is chosen. A seeded site
        // would land there already; this makes the test independent of that.
        const libraryTab = page.locator('.media-router .media-menu-item', {
            hasText: /media library/i,
        });

        if (await libraryTab.count()) {
            await libraryTab.click();
        }

        const column = page.locator('.folderfolio-frame');
        await column.waitFor();

        // Screen 10: 240px, and the row geometry restated by the container
        // rather than re-implemented. If these read 36/24/20 the cramped
        // variant is not applying and the same component is being drawn at the
        // rail's size inside a 240px box.
        const geometry = await column.evaluate((el) => {
            const cs = getComputedStyle(el);

            return {
                width: el.getBoundingClientRect().width,
                row: cs.getPropertyValue('--ff-row-h').trim(),
                indent: cs.getPropertyValue('--ff-indent').trim(),
                switcher: cs.getPropertyValue('--ff-switcher').trim(),
            };
        });

        expect(geometry.row).toBe('32px');
        expect(geometry.indent).toBe('20px');
        expect(geometry.switcher).toBe('16px');
        // 240 plus the 2px rule against the attachments.
        expect(geometry.width).toBeGreaterThanOrEqual(240);
        expect(geometry.width).toBeLessThan(250);

        // And it is a column of the frame, not something floating over it.
        await expect(page.locator('.media-modal .media-frame-content .attachments-browser')).toBeVisible();
    });
});
