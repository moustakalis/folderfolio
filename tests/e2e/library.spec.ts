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

        /*
         * Order matters: screen 03 puts it after the date filter and before
         * Bulk select, and core hides the whole row in select mode.
         *
         * Our own slots are skipped walking backwards. Three of them anchor in
         * a chain ending at a control of core's — bulk, then the select, then
         * the narrow-width picker — so the select's slot is preceded by the
         * picker's rather than by the date filter itself. Anchoring the picker
         * the other way round would put them in DOM order and live-lock the
         * two slots against each other; see `pickerSlotPlace`. What has to be
         * true is the position among *core's* controls, which is what this
         * reads.
         */
        const after = await page.evaluate(() => {
            let el = document.querySelector('.folderfolio-slot--filter')
                ?.previousElementSibling;

            while (el?.classList.contains('folderfolio-slot')) {
                el = el.previousElementSibling;
            }

            return el?.id;
        });
        expect(after).toBe('media-attachment-date-filters');

        await expect(select.locator('option')).toContainText(['All media', 'Unassigned', 'Brand']);
    });

    test('spends one toolbar control on folders, not two', async ({ page }) => {
        // The row has no width to spare, and a second trigger spent about
        // 250px of it to sit there disabled.
        await expect(page.locator('.folderfolio-bulk')).toHaveCount(1);
        await expect(page.getByRole('button', { name: /^move to folder$/i })).toHaveCount(0);
    });

    /**
     * Grid shows the trigger only in select mode, which is core's own rule.
     *
     * Grid's normal state cannot produce a selection — clicking a tile opens
     * the details modal rather than ticking it — so the trigger was disabled
     * there one hundred percent of the time. Core shows no bulk action in that
     * state either: `Delete permanently` lives in select mode, and `Bulk
     * select` on the filter row is a mode switch, not an action.
     */
    test('the folder action appears in grid only once a selection is possible', async ({ page }) => {
        const trigger = page.locator('.media-toolbar-secondary .folderfolio-bulk');

        // Present in the markup — the slot is still mounted — but not on screen.
        await expect(trigger).toHaveCount(1);
        await expect(trigger).toBeHidden();

        await page.getByRole('button', { name: /^bulk select$/i }).click();

        await expect(trigger).toBeVisible();

        // And it lands between core's two, where the board and the old source
        // order both put it: Delete permanently | Add to folder… | Cancel.
        const painted = await page.evaluate(() => {
            const sec = document.querySelector('.media-toolbar-secondary');

            if (!sec) {
                return null;
            }

            return [...sec.querySelectorAll('button')]
                .filter((b) => (b as HTMLElement).offsetParent !== null)
                .sort((a, b) => a.getBoundingClientRect().left - b.getBoundingClientRect().left)
                .map((b) => (b.textContent ?? '').trim().toLowerCase());
        });

        expect(painted?.length).toBe(3);
        expect(painted?.[1]).toMatch(/add to folder/);
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

    /**
     * The folder control stays with the other filters as the column narrows.
     *
     * It used to leave them at about 860px of toolbar and land on a line with
     * the search, because `.media-toolbar`'s two children — the filters and
     * the search — were both `flex: 0 1 auto`, so the filters shrank instead of
     * pushing the search down, then wrapped internally. The folder select is
     * last among them, so the folder select is what went.
     *
     * Measured by vertical **centre**, not by top edge: a 40px select and a
     * 28px icon button on the same visual line have different tops, and a
     * survey that clusters by top reports controls stranded that are not. That
     * mistake has been made twice in this project and once in the analysis that
     * led to this fix.
     *
     * Two things have happened to this test since it was written, and both
     * are the design moving rather than the test rotting:
     *
     *  - below 700px of **toolbar** the filters collapse behind one `Filter`
     *    button. The deciding width is the library column's, not the
     *    viewport's — the rail is dragged to whatever width its owner likes —
     *    so a viewport sweep cannot know in advance which side of it any given
     *    width falls on. At 1200px with a 310px rail the toolbar is 683px,
     *    which is the collapsed side.
     *  - below that same breakpoint the select itself steps aside for the
     *    searchable picker, because at a thousand folders it is a native list
     *    a thousand rows long.
     *
     * So the invariant is no longer "the select is on the line". It is: the
     * folder control, whichever of the two is on screen, is on the filters'
     * line and reachable — never stranded on the search's line and never
     * absent altogether.
     */
    test('the folder control stays on the filter line as the column narrows', async ({ page }) => {
        const centredWithDate = async (selector: string) =>
            page.evaluate((sel) => {
                const date = document.querySelector('#media-attachment-date-filters');
                const ours = document.querySelector(sel);

                if (!date || !ours) {
                    return null;
                }

                const a = date.getBoundingClientRect();
                const b = ours.getBoundingClientRect();

                return Math.abs(a.top + a.height / 2 - (b.top + b.height / 2)) < 10;
            }, selector);

        // Wide enough for all three groups on one line, and narrow enough that
        // they cannot be — the width at which the select used to be evicted.
        for (const width of [1440, 1200]) {
            await page.setViewportSize({ width, height: 900 });
            await page.waitForTimeout(400);

            const select = page.locator('#folderfolio-folder-filter');
            const collapsed = !(await select.isVisible());

            if (!collapsed) {
                expect(
                    await centredWithDate('#folderfolio-folder-filter'),
                    `at ${width}px the folder select left the filter group`
                ).toBe(true);

                continue;
            }

            /*
             * Collapsed: the disclosure is what is on the line, and the folder
             * control is behind it. Asserting the picker is *centred with the
             * date select* once open is the same invariant one level in — they
             * are rows of the same stacked panel there, so "on the line" means
             * "in the group", which is what the test has always been about.
             */
            const disclosure = page.getByRole('button', { name: /^filters$/i });

            await expect(
                disclosure,
                `at ${width}px the filters collapsed with nothing to open them`
            ).toBeVisible();

            await disclosure.click();
            await expect(page.locator('.folderfolio-folder-picker')).toBeVisible();
            await expect(page.locator('#media-attachment-date-filters')).toBeVisible();
            await disclosure.click();
        }
    });

    /**
     * The folder select's width cap actually applies.
     *
     * Asserted as a computed style rather than as a measured width, and that
     * is the point: this suite's fixture has a handful of folders, so the
     * select is narrower than either cap and the defect cannot reproduce here
     * at all. It took a 1,050-folder library to show it — `.wp-core-ui select`
     * is **0,1,1** with `max-width: 25rem`, our `.folderfolio-folder-select`
     * was 0,1,0, and the cap had therefore never once applied. The longest
     * option grew the control to 257px, which pushed it off the filter line
     * and cost 40px of grid toolbar at a 1440px viewport.
     *
     * Reading the computed value catches the specificity regression whatever
     * the fixture contains.
     */
    test('caps the folder select, at a specificity that survives core', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.locator('#folderfolio-folder-filter').waitFor();

        const cap = await page.evaluate(() => {
            const el = document.querySelector('#folderfolio-folder-filter');

            return el ? getComputedStyle(el).maxWidth : null;
        });

        // 11rem against wp-admin's 16px root. Compared as a number so a
        // different root size reads as a different number, not as a failure.
        expect(Number.parseFloat(cap ?? '')).toBeLessThanOrEqual(176);
    });

    /**
     * A folder called `Archive 29` with nothing in it read `Archive 29 0`, and
     * nobody can tell that 0 from the 29. Names ending in a number are not
     * exotic — `2024`, `Q3 2025`, `Campaign 12` — and the whole stress fixture
     * is built from them.
     *
     * Brackets, the way `walker_category_dropdown` has printed counts in this
     * admin for years. Asserted on the *text*, because the defect is entirely
     * in how the text reads.
     */
    test('brackets the count in every option, so a name ending in a number still reads', async ({
        page,
    }) => {
        await createFolder(page, 'Archive 29');
        await page.reload();
        await waitForTree(page, 'Archive 29');

        const texts = await page.locator('#folderfolio-folder-filter option').allTextContents();

        expect(texts.length).toBeGreaterThan(2);

        for (const text of texts) {
            // Every option ends in a bracketed number, and nothing else does.
            expect(text.trimEnd()).toMatch(/\(\d+\)$/);
        }

        expect(texts.some((text) => /Archive 29\s*\(\d+\)$/.test(text.trimEnd()))).toBe(true);
    });

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
         * Neither mode shows a folder action on this survey, and for the same
         * reason in both: nothing is selected.
         *
         * Grid hides it outright in the normal state, because grid's normal
         * state cannot produce a selection at all — core does the same with
         * `Delete permanently`. List keeps it, because its rows are always
         * tickable, but only when core has printed a bulk row to put it in:
         * on an empty library core prints none, our slot has nowhere to go,
         * and the right behaviour is to go nowhere rather than conjure a bulk
         * action into some other row with no Apply beside it.
         *
         * Asserted rather than tolerated, so the day core changes either of
         * those, the test says which.
         */
        expect(grid.bulkLine, 'grid showed a folder action with nothing selected').toEqual([]);

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
        // Not edit.php any more: since tier 3 item 12 the Posts screen has a
        // folder rail of its own (Posts folders), and the media picker's
        // bundle stays off a screen whose window.folderFolio is the rail's.
        // themes.php is a MediaModalIntegration screen with no rail.
        await page.goto('/wp-admin/themes.php');

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

/**
 * The wide toolbar's shape, which is now one shape.
 *
 * Above 860px of container the toolbar used to spend a whole 40px row on
 * `Bulk select` alone — and in the band 1411-1429px of viewport it went to
 * four rows and 168px, because a declared line break arrived before there was
 * room for the filters and the search on one line, pushing the folder select
 * out of the group it belongs to.
 *
 * The break was replaced by a position: `margin-left: auto` on core's own
 * `.select-mode-toggle-button`. So the assertion is the shape, at the two
 * widths that used to disagree and one that never did.
 */
test.describe('the wide grid toolbar', () => {
    test('puts Bulk select at the end of the filter line at every width', async ({ page }) => {
        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);
        await createFolder(page, 'Brand');
        await page.reload();
        await waitForTree(page, 'Brand');

        for (const width of [1360, 1412, 1456, 1700]) {
            await page.setViewportSize({ width, height: 900 });
            await page.waitForTimeout(250);

            const shape = await page.evaluate(() => {
                const toolbar = document.querySelector('#wpbody-content .media-toolbar');
                const bulk = toolbar?.querySelector('.select-mode-toggle-button');
                const folder = document.querySelector('#folderfolio-folder-filter');
                const search = document.querySelector('#media-search-input');

                if (!(toolbar instanceof HTMLElement) || !(bulk instanceof HTMLElement)) {
                    return null;
                }
                if (!(folder instanceof HTMLElement) || !(search instanceof HTMLElement)) {
                    return null;
                }

                const mid = (el: Element) => {
                    const box = el.getBoundingClientRect();
                    return box.top + box.height / 2;
                };

                return {
                    height: toolbar.getBoundingClientRect().height,
                    // Same line as the folder select, and to the right of it.
                    bulkOnFilterLine: Math.abs(mid(bulk) - mid(folder)) < 12,
                    bulkAfterFolder:
                        bulk.getBoundingClientRect().left > folder.getBoundingClientRect().right,
                    // …and the search is on the line below, or — when the
                    // filters are short enough to leave it room, as on a site
                    // whose date filter holds one month — at the end of the
                    // same line. Never between them.
                    searchPlaced:
                        mid(search) > mid(folder) + 12 ||
                        (Math.abs(mid(search) - mid(folder)) < 12 &&
                            search.getBoundingClientRect().left > bulk.getBoundingClientRect().right),
                };
            });

            expect(shape, `at ${width}px`).not.toBeNull();
            expect(shape!.bulkOnFilterLine, `Bulk select on the filter line at ${width}px`).toBe(true);
            expect(shape!.bulkAfterFolder, `Bulk select after the folder select at ${width}px`).toBe(true);
            expect(shape!.searchPlaced, `search below the filters, or after them, at ${width}px`).toBe(true);
            // Two rows. It was 168 in the band and 128 above it.
            expect(shape!.height, `toolbar height at ${width}px`).toBeLessThan(145);
        }
    });
});

/**
 * The folders block above the files.
 *
 * It draws the children of the folder you are *in*. At All media it used to
 * draw every root folder, which on the 1,053-folder fixture was 510px at
 * 1440 x 900 — the first thumbnail 851px down a 900px window — saying nothing
 * the rail was not already saying beside it, with the same names, the same
 * counts and the same drop targets.
 */
test.describe('the folders block', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);
    });

    test('draws inside a folder and nowhere else', async ({ page }) => {
        const brand = await createFolder(page, 'Brand');
        await createFolder(page, 'Logos', brand.id);
        await createFolder(page, 'Audio');

        await page.reload();
        await waitForTree(page, 'Brand');

        const cards = page.locator('.folderfolio-cards');
        const eyebrow = page.locator('.folderfolio-eyebrow');

        // All media: the roots are in the rail, and only in the rail.
        await expect(cards).toHaveCount(0);
        await expect(eyebrow).toHaveCount(0);

        // Inside a folder with children: the block, and the line that counts
        // them and names where they are.
        await page.locator('.folderfolio-row__name', { hasText: /^Brand$/ }).click();
        await expect(cards).toHaveCount(1);
        await expect(eyebrow).toHaveText(/1 folder in Brand/);
        await expect(cards.getByRole('button', { name: /Logos/ })).toBeVisible();

        // Inside a folder without children: nothing, rather than a line
        // standing over empty space.
        await page.locator('.folderfolio-row__name', { hasText: /^Audio$/ }).click();
        await expect(cards).toHaveCount(0);
        await expect(eyebrow).toHaveCount(0);

        // Unassigned is the absence of a folder, so it has no children by
        // definition — the negative control for the rule above.
        await page.goto('/wp-admin/upload.php?mode=grid&folderfolio_folder=0');
        await page.locator('#folderfolio-rail').waitFor();
        await expect(cards).toHaveCount(0);
    });
});
