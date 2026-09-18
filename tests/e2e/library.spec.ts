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

    test('spends one toolbar control on folders, not two', async ({ page }) => {
        // The row has no width to spare, and a second trigger spent about
        // 250px of it to sit there disabled — which is what both buttons were
        // whenever nothing was selected, which is most of the time.
        await expect(page.getByRole('button', { name: /add to folder/i })).toHaveCount(1);
        await expect(page.getByRole('button', { name: /^move to folder$/i })).toHaveCount(0);

        // Disabled until there is a selection, and that is now its only reason
        // to be — which is visible on the screen behind it and needs no title.
        await expect(page.getByRole('button', { name: /add to folder/i })).toBeDisabled();
    });

    /*
     * The move verb itself is exercised in upload.spec.ts, not here.
     *
     * Opening the flyout needs a selection, a selection needs an attachment,
     * and a fresh Playground's library is empty — so a test here would have to
     * upload one, which costs about twenty seconds on php-wasm. That spec
     * already uploads, and already has a folder selected when it does, which
     * is the state in which Move is enabled rather than greyed.
     */

    test('keeps the bulk controls when core rebuilds the toolbar', async ({ page }) => {
        // Entering Bulk select re-renders the media frame's toolbar and hides
        // every child of it but three. The slot has to survive that, and the
        // Add-to-folder control has to stay visible while the filters go.
        await page.getByRole('button', { name: /bulk select/i }).click();

        await expect(page.getByRole('button', { name: /add to folder/i })).toBeVisible();
        await expect(page.locator('#folderfolio-folder-filter')).toBeHidden();
    });

    /**
     * The whole toolbar, in both modes, compared line by line.
     *
     * The two library modes do not share a toolbar: grid has one Backbone
     * `.media-toolbar`; list has a `.wp-filter` **and** a separate
     * `.tablenav.top`, with Filter and Search Media buttons because
     * `#posts-filter` is a GET form, `Bulk actions` + Apply instead of
     * `Bulk select`, and pagination the grid does not have. None of that is
     * ours to change.
     *
     * What *is* ours is where our three controls sit inside it, and the answer
     * has to be the same in both modes or the same control is in two places
     * depending on a view toggle. So this does not check one control's
     * position — it surveys **every** control in the library chrome, groups
     * them into lines by their top edge, classifies each one, and compares the
     * shapes:
     *
     *     filter line   view · view · filter · filter · FOLDER  [· commit]
     *     bulk line     [· bulk] · ADD · commit
     *
     * An earlier version of the grid's line break put our select on its own
     * row, which left it fifth on core's filter line in list and first on a
     * second row in grid. Measuring only the select would not have caught
     * that; measuring only grid would not have either.
     */
    async function toolbar(page: import('@playwright/test').Page) {
        return page.evaluate(() => {
            const seen = new Set<Element>();
            const found: Array<{ kind: string; top: number; left: number }> = [];

            for (const root of document.querySelectorAll(
                '.media-toolbar, .wp-filter, .tablenav.top'
            )) {
                for (const el of root.querySelectorAll(
                    'select, input:not([type=hidden]), button, a.button, .view-switch a, .tablenav-pages'
                )) {
                    if (seen.has(el)) {
                        continue;
                    }

                    const box = el.getBoundingClientRect();

                    if (box.width <= 2 || (el as HTMLElement).offsetParent === null) {
                        continue;
                    }

                    seen.add(el);

                    // Classified, not read: core's ids differ between the two
                    // modes and every label is translatable.
                    const ours = el.closest('.folderfolio-slot') !== null;
                    const text = (el.textContent ?? '').toLowerCase();

                    const kind = el.id === 'folderfolio-folder-filter'
                        ? 'FOLDER'
                        : ours && text.includes('add')
                          ? 'ADD'
                          : ours && text.includes('move')
                            ? 'MOVE'
                            : el.closest('.view-switch')
                              ? 'view'
                              : el.classList.contains('tablenav-pages')
                                ? 'pages'
                                : el.tagName === 'SELECT'
                                  ? 'filter'
                                  : el.tagName === 'INPUT' && el.getAttribute('type') === 'search'
                                    ? 'search'
                                    : 'commit';

                    found.push({ kind, top: Math.round(box.top), left: Math.round(box.left) });
                }
            }

            found.sort((a, b) => a.top - b.top || a.left - b.left);

            const lines: Array<{ top: number; items: typeof found }> = [];

            for (const item of found) {
                const last = lines[lines.length - 1];

                if (last && item.top - last.top < 20) {
                    last.items.push(item);
                } else {
                    lines.push({ top: item.top, items: [item] });
                }
            }

            // Each line back into reading order. Controls of different heights
            // sit a few pixels apart on the same line, so the global sort that
            // built the clusters ordered them by top *within* a line too —
            // which put a 40px select before a 28px icon beside it and made
            // "comes before" mean nothing.
            return lines.map((line) =>
                line.items.sort((a, b) => a.left - b.left).map((item) => item.kind)
            );
        });
    }

    test('lays the toolbar out the same way in both library modes', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });

        const shapeOf = (lines: string[][]) => ({
            // Where the folder select sits among the controls on its line, and
            // what precedes it.
            filterLine: lines.find((l) => l.includes('FOLDER')) ?? [],
            bulkLine: lines.find((l) => l.includes('ADD')) ?? [],
        });

        /*
         * Waiting for the control itself, not for a timeout. The bulk slot is
         * moved into core's markup by an effect that cannot run until core has
         * printed the row it belongs in — `.media-toolbar-secondary` in grid,
         * `.tablenav .bulkactions` in list — and on php-wasm that is well past
         * any number worth hard-coding. A survey taken a moment early reports
         * the control as missing, which reads exactly like the defect this
         * test is for.
         */
        const settled = async () => {
            await page.locator('#folderfolio-rail').waitFor();
            await page.locator('#folderfolio-folder-filter').waitFor();
            await page.waitForTimeout(400);
        };

        await page.goto('/wp-admin/upload.php?mode=grid');
        await settled();
        const grid = shapeOf(await toolbar(page));

        await page.goto('/wp-admin/upload.php?mode=list');
        await settled();
        const list = shapeOf(await toolbar(page));

        /*
         * The folder select is the **last** filter on core's filter line, in
         * both modes.
         *
         * Last, rather than "third of three": core drops the date filter when
         * every attachment is from one month, which a fresh Playground always
         * is — so counting core's filters asserts the fixture, not the layout.
         */
        for (const [mode, shape] of [['grid', grid], ['list', list]] as const) {
            const filters = shape.filterLine.filter((k) => k === 'filter' || k === 'FOLDER');

            expect(
                filters.length,
                `${mode}: no core filter on the folder select's line`
            ).toBeGreaterThan(1);
            expect(
                filters[filters.length - 1],
                `${mode}: the folder select is not the last filter on core's filter line`
            ).toBe('FOLDER');

            if (shape.bulkLine.length > 0) {
                // One folder control here, not two. The move verb lives inside
                // the flyout now; see AddToFolder's docblock.
                expect(
                    shape.bulkLine.filter((k) => k === 'ADD' || k === 'MOVE'),
                    `${mode}: expected exactly one folder action on the bulk line`
                ).toEqual(['ADD']);

                expect(
                    shape.bulkLine.includes('FOLDER'),
                    `${mode}: the bulk action shares a line with the folder select`
                ).toBe(false);
            }
        }

        /*
         * Grid always has the pair. List only has it when core has a bulk row
         * to put it in — and on an empty library core prints none at all,
         * because there is nothing to act on. Our slot has nowhere to go then,
         * and the right behaviour is to go nowhere: a bulk action conjured
         * into some other row, with no files and no Apply beside it, would be
         * a control that cannot do anything and does not look like it.
         *
         * Asserted rather than tolerated, so that the day core changes this,
         * the test says which of the two happened.
         */
        expect(grid.bulkLine, 'grid lost the bulk pair').not.toEqual([]);

        // Visible, not merely present: on an empty library core still prints
        // the bulk row and then hides it, and the survey above only counts
        // what is on screen. Comparing a DOM-presence check against a
        // visibility survey reports a defect that is not there — which it did.
        const listHasCoreBulkRow = await page
            .locator('.tablenav.top .bulkactions select')
            .first()
            .isVisible()
            .catch(() => false);

        expect(
            list.bulkLine.length > 0,
            listHasCoreBulkRow
                ? 'list has core bulk row but not ours'
                : 'list has no core bulk row, so ours should not be anywhere either'
        ).toBe(listHasCoreBulkRow);

        /*
         * And the two modes agree about *our* place in the line — not about
         * the line itself.
         *
         * Comparing the two shapes for equality was the obvious assertion and
         * the wrong one: core drops the date dropdown when every attachment is
         * from one month, and it drops it in list mode only, so an empty
         * library gives grid two core filters and list one. That is core's
         * business. What has to hold in both is where ours sits — after the
         * view switch, after every filter core printed, and last.
         */
        for (const [mode, shape] of [['grid', grid], ['list', list]] as const) {
            const line = shape.filterLine;

            expect(
                line.indexOf('view'),
                `${mode}: the folder select comes before the view switch`
            ).toBeLessThan(line.indexOf('FOLDER'));

            expect(
                line.lastIndexOf('filter'),
                `${mode}: a core filter comes after the folder select`
            ).toBeLessThan(line.indexOf('FOLDER'));
        }
    });

    /**
     * Select mode hides the filter row, and with it the declared break.
     *
     * A hidden element generates no pseudo-element, so the bulk actions come
     * back up to one line. If the break were a real element instead, this is
     * where it would show: an empty line where the filters used to be.
     */
    test('collapses to a single line in select mode', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.getByRole('button', { name: /bulk select/i }).click();
        await page.waitForTimeout(400);

        const lines = await toolbar(page);
        const bulk = lines.findIndex((l) => l.includes('ADD'));

        expect(bulk, 'select mode left a line above the bulk actions').toBe(0);
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
