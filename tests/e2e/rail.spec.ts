import { expect, test } from '@playwright/test';

import { createFolder, folderNames, resetFolders, waitForTree } from './helpers/folders';

/**
 * The folder rail on Media > Library.
 *
 * These replace a suite written against v0.2.0's markup, which by step 10 had
 * become two files asserting on ids that no longer exist — a red CI run that
 * said nothing about the plugin. What is checked here is the behaviour the
 * design handoff specifies, not the DOM it happens to produce: where the rail
 * mounts, that choosing a folder never reloads, and that the tree keeps its
 * keyboard contract.
 */

test.describe('the folder rail', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/wp-admin/upload.php');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);
        await page.reload();
        await page.locator('#folderfolio-rail').waitFor();
    });

    test('mounts as a sibling of the content column, not inside the notices', async ({ page }) => {
        // v0.2.0 rendered into all_admin_notices, which is why its rail
        // scrolled away with the page and a plugin update notice could push it
        // down the screen. The order below is the fix, and it is load-bearing.
        const order = await page.evaluate(() =>
            [...(document.getElementById('wpbody')?.children ?? [])].map((el) => el.id || el.className)
        );

        expect(order.slice(0, 3)).toEqual([
            'folderfolio-rail',
            'folderfolio-rail-handle',
            'wpbody-content',
        ]);
    });

    // WordPress 7.x's media grid clears the address bar when it starts its
    // history: its router's reset empties the search field, and the field's
    // handler navigates to a bare upload.php (found through CI on 24 Sep —
    // Playground is 7.x, the container's rig 6.8.2, where this held anyway).
    test('a folder in the address bar is still there once the grid has loaded', async ({ page }) => {
        const folder = await createFolder(page, 'Addressed');

        await page.goto(`/wp-admin/upload.php?mode=grid&folderfolio_folder=${folder.id}`);
        await waitForTree(page, 'Addressed');
        // Core's search handler is throttled to a second.
        await page.waitForTimeout(1500);

        expect(new URL(page.url()).searchParams.get('folderfolio_folder')).toBe(String(folder.id));
    });

    test('shows All media and Unassigned above the tree', async ({ page }) => {
        const fixed = page.locator('.folderfolio-rail__fixed .folderfolio-row');

        await expect(fixed).toHaveCount(2);
        await expect(fixed.first()).toContainText('All media');
        await expect(fixed.nth(1)).toContainText('Unassigned');
    });

    test('creates a folder inline, in the tree rather than in a dialog', async ({ page }) => {
        await page.getByRole('button', { name: /new folder/i }).click();

        const input = page.locator('.folderfolio-row__input');
        await expect(input).toBeFocused();

        await input.fill('Brand');
        await input.press('Enter');

        await expect(page.locator('.folderfolio-tree')).toContainText('Brand');
        expect(await folderNames(page)).toContain('Brand');
    });

    test('selecting a folder filters without reloading the page', async ({ page }) => {
        const folder = await createFolder(page, 'Campaigns');
        await page.reload();
        await waitForTree(page, 'Campaigns');

        // A marker that cannot survive a navigation. This is the whole point
        // of lib/list-refresh.ts and of re-querying the grid in place.
        await page.evaluate(() => {
            (window as unknown as { __ff: string }).__ff = 'alive';
        });

        await page.locator('.folderfolio-row', { hasText: 'Campaigns' }).click();

        await expect(page).toHaveURL(new RegExp(`folderfolio_folder=${folder.id}`));
        await expect(page.locator('.folderfolio-crumbs')).toContainText('Campaigns');

        /*
         * And it does not open with a separator.
         *
         * Every crumb carries the slash that precedes it, so the first one's
         * is hidden — by one CSS rule and one comment, which is the kind of
         * thing that disappears in a refactor without any assertion noticing.
         * The 18 Sep audit filed a leading "/" as a defect; it does not
         * reproduce, because the rule is there. This is what makes that answer
         * stay true.
         */
        expect(
            (await page.locator('.folderfolio-crumbs').innerText()).trimStart().startsWith('/'),
            'the breadcrumb must not open with a separator'
        ).toBe(false);

        expect(
            await page.evaluate(() => (window as unknown as { __ff?: string }).__ff)
        ).toBe('alive');
    });

    test('deleting offers undo, and undo means the server was never told', async ({ page }) => {
        await createFolder(page, 'Archive');
        await page.reload();
        await waitForTree(page, 'Archive');

        // Delete lives on the selected row's ⋮ since the toolbar went (20 Sep).
        await page.locator('.folderfolio-row', { hasText: 'Archive' }).click();
        await page.locator('.folderfolio-row__menu').click();
        await page.getByRole('menuitem', { name: /^delete$/i }).click();

        // The rail's body, not the tree: deleting the only folder replaces
        // the tree with the empty state, and a `not.toContainText` on a
        // locator that no longer exists fails rather than passes.
        await expect(page.locator('.folderfolio-rail__body')).not.toContainText('Archive');

        const toast = page.locator('.folderfolio-toast');
        await expect(toast).toBeVisible();
        await expect(toast).toContainText('Archive');

        await toast.getByRole('button', { name: /undo/i }).click();

        await expect(page.locator('.folderfolio-rail__body')).toContainText('Archive');
        // The real assertion: not that the row came back, but that the folder
        // is still on the server — undo is "do not send the delete", not
        // "create it again", because the id is what every assignment refers to.
        expect(await folderNames(page)).toContain('Archive');
    });
});

test.describe('the breadcrumb', () => {
    /**
     * The way out of a filter, on the crumb that states it.
     *
     * The alternative — clicking the selected rail row to toggle it off — was
     * weighed and rejected: the narrow sheet's own header is already a
     * press-to-filter control on the folder you are in, `Enter` on a focused
     * tree row is the key that filters, and a folder row invites a Finder
     * double-click. All three would have meant something different under a
     * toggle. A control of its own has none of those collisions, so that is
     * what this asserts — including the rule that decides where it appears.
     */
    test('the last crumb carries a clear, and only when there is a filter to clear', async ({
        page,
    }) => {
        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);
        await createFolder(page, 'Brand');
        await page.reload();
        await waitForTree(page, 'Brand');

        // The library's crumb row carries a labelled button (22 Sep); the
        // icon-only `__clear` is the media picker's compact one.
        const clear = page.locator('.folderfolio-crumbs__control', { hasText: /clear filter/i });

        // All media is not a state there is anything to clear.
        await expect(clear).toHaveCount(0);

        await page.locator('.folderfolio-row__name', { hasText: /^Brand$/ }).click();
        await expect(page).toHaveURL(/folderfolio_folder=\d+/);
        await expect(clear).toHaveCount(1);

        // The hard rule holds through it: no reload, in either direction.
        await page.evaluate(() => {
            (window as unknown as { __ffMark?: string }).__ffMark = 'kept';
        });

        await clear.click();

        // Cleared is `folderfolio_folder=` with no value, not the key gone:
        // the empty spelling is what stops a startup folder's redirect from
        // putting the person back where they were (lib/filter.ts).
        await expect(page).not.toHaveURL(/folderfolio_folder=\d/);
        await expect(clear).toHaveCount(0);
        await expect(page.locator('.folderfolio-crumbs')).toHaveText(/All media/);
        expect(
            await page.evaluate(
                () => (window as unknown as { __ffMark?: string }).__ffMark === 'kept'
            )
        ).toBe(true);
    });
});

test.describe('the tree keyboard', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/wp-admin/upload.php');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);

        const brand = await createFolder(page, 'Brand');
        await createFolder(page, 'Logos', brand.id);
        await createFolder(page, 'Campaigns');

        await page.reload();
        await waitForTree(page, 'Brand');
    });

    const tabbable = (page: import('@playwright/test').Page) =>
        page.locator('.folderfolio-row[tabindex="0"]');

    test('is a single tab stop', async ({ page }) => {
        await expect(tabbable(page)).toHaveCount(1);
    });

    test('arrowing moves focus without re-filtering the library', async ({ page }) => {
        const url = page.url();

        await page.locator('.folderfolio-tree .folderfolio-row').first().focus();
        await page.keyboard.press('ArrowDown');

        await expect(tabbable(page)).toHaveCount(1);
        await expect(tabbable(page)).toContainText('Campaigns');

        // Focus is tracked apart from selection precisely so that this holds.
        expect(page.url()).toBe(url);
    });

    test('Right expands, then steps into the branch; Left comes back out', async ({ page }) => {
        await page.locator('.folderfolio-tree .folderfolio-row').first().focus();

        await page.keyboard.press('ArrowRight');
        await expect(page.locator('.folderfolio-tree')).toContainText('Logos');
        await expect(tabbable(page)).toContainText('Brand');

        await page.keyboard.press('ArrowRight');
        await expect(tabbable(page)).toContainText('Logos');

        await page.keyboard.press('ArrowLeft');
        await expect(tabbable(page)).toContainText('Brand');
    });

    test('collapsing a branch the focused row is inside keeps a way back in', async ({ page }) => {
        await page.locator('.folderfolio-tree .folderfolio-row').first().focus();
        await page.keyboard.press('ArrowRight');
        await page.keyboard.press('ArrowRight');
        await expect(tabbable(page)).toContainText('Logos');

        // Collapse the parent from its switcher — the pointer path, while
        // focus is on a row inside it.
        await page
            .locator('.folderfolio-row', { hasText: 'Brand' })
            .locator('.folderfolio-row__switcher')
            .click();

        // Exactly one, and on the row that was collapsed rather than dropped
        // to the top of the tree. Without this the tree leaves the tab order
        // entirely and nothing on screen looks wrong.
        await expect(tabbable(page)).toHaveCount(1);
        await expect(tabbable(page)).toContainText('Brand');
    });

    test('Enter is the only key that filters', async ({ page }) => {
        await page.locator('.folderfolio-tree .folderfolio-row').first().focus();
        await page.keyboard.press('ArrowDown');
        await page.keyboard.press('Enter');

        await expect(page).toHaveURL(/folderfolio_folder=\d+/);
        await expect(page.locator('.folderfolio-crumbs')).toContainText('Campaigns');
    });
});

/**
 * Reordering a folder — the three gestures that are one operation.
 *
 * A drag cannot be asserted here honestly: HTML5 drag events are never fired
 * by touch and Playwright's mouse-driven `dragTo` synthesises them, so a
 * green drag test would be testing the synthesiser. What is checked is the
 * contract underneath it — `planSiblingMove` reached through the two gestures
 * that use real events — and the one property the drag shares with them: the
 * arrangement is only visible under Custom, so making one must select it.
 *
 * The toolbar case runs at both widths deliberately. `useFolderDrop` and
 * Alt+Arrow both live in `Tree.tsx`, which is not drawn below 782px, so until
 * More carried these two items the feature did not exist on a phone at all.
 * A regression there would be silent at 1280px.
 */
test.describe('reordering a folder', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/wp-admin/upload.php');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);

        const parent = await createFolder(page, 'Level');
        for (const name of ['Alpha', 'Bravo', 'Charlie']) {
            await createFolder(page, name, parent.id);
        }

        await page.reload();
        await waitForTree(page, 'Level');
    });

    /** The probe folders in the order the rail is drawing them. */
    const arrangement = (page: import('@playwright/test').Page) =>
        page.evaluate(() => {
            // The tree's rows carry the name as data; the narrow sheet's
            // `Levels` rows only as text.
            const tagged = [...document.querySelectorAll('[data-folderfolio-name]')].map((el) =>
                el.getAttribute('data-folderfolio-name')
            );
            const names = tagged.length
                ? tagged
                : [...document.querySelectorAll('.folderfolio-levels__row .folderfolio-row__name')].map(
                      (el) => el.textContent?.trim() ?? ''
                  );

            return names.filter((name): name is string => /^(Alpha|Bravo|Charlie)$/.test(name ?? ''));
        });

    async function openLevel(page: import('@playwright/test').Page) {
        await page
            .locator('.folderfolio-row', { hasText: 'Level' })
            .locator('.folderfolio-row__switcher')
            .click();
    }

    test('Alt+ArrowDown moves a folder one place and selects Custom order', async ({ page }) => {
        await openLevel(page);
        // A click, not `.focus()`: the tree's keys act on the store's
        // focusedId, which a click (select) sets and a bare DOM focus on a
        // tabindex=-1 row does not.
        await page.locator('.folderfolio-row', { hasText: 'Alpha' }).first().click();
        await page.keyboard.press('Alt+ArrowDown');

        await expect.poll(() => arrangement(page)).toEqual(['Bravo', 'Alpha', 'Charlie']);

        // The arrangement exists in sort_order whatever the sort; it is only
        // *visible* under Custom. A gesture that saved one and left the tree
        // showing another order is the half-finished version of this feature.
        await page.getByRole('button', { name: /^sort$/i }).click();
        await expect(page.getByRole('menuitemradio', { name: /custom order/i })).toHaveAttribute(
            'aria-checked',
            'true'
        );
    });

    for (const width of [1280, 600]) {
        test(`More > Move down reorders at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 900 });

            if (width > 782) {
                await openLevel(page);
                await page.locator('.folderfolio-row', { hasText: 'Alpha' }).first().click();
                // The selected row's ⋮ — the toolbar's More went on 20 Sep.
                await page.locator('.folderfolio-row__menu').click();
            } else {
                // Below 782px the rail is a sheet behind its tab, drawn by
                // `Levels`, and the menu is the controls row's Folder actions.
                await page.reload();
                await page.locator('.folderfolio-rail__tab').click();
                // The drill-down: there is no expand here, the row walks in.
                await page.locator('.folderfolio-levels__row', { hasText: 'Level' }).click();
                // Alpha is a leaf, so tapping it selects and stays put — the
                // case where the moved folder and its siblings are both on
                // screen.
                await page.locator('.folderfolio-levels__row', { hasText: 'Alpha' }).click();
                await page.getByRole('button', { name: /^folder actions$/i }).click();
            }

            await page.getByRole('menuitem', { name: /move down/i }).click();

            await expect.poll(() => arrangement(page)).toEqual(['Bravo', 'Alpha', 'Charlie']);
        });
    }

    // Nick, 24 Sep: "more panel hides below toolbar". Below 782px the rail is
    // a bar above the library, and core's media toolbar (relative, z-index
    // 100) tied the menu and, later in the document, painted over it.
    test('below 782px the folder menu is drawn over the media toolbar', async ({ page }) => {
        await page.setViewportSize({ width: 600, height: 1400 });
        await page.reload();
        await page.locator('.folderfolio-rail__tab').click();
        await page.locator('.folderfolio-levels__row', { hasText: 'Level' }).click();
        await page.locator('.folderfolio-levels__row', { hasText: 'Alpha' }).click();
        await page.getByRole('button', { name: /^folder actions$/i }).click();

        const cover = await page.evaluate(() => {
            const menu = document.querySelector('.folderfolio-menu--folder')!;
            const toolbar = document.querySelector('.media-toolbar')!.getBoundingClientRect();
            const box = menu.getBoundingClientRect();
            // The part of the menu the toolbar's box also covers.
            const top = Math.max(box.top, toolbar.top) + 4;
            const bottom = Math.min(box.bottom, toolbar.bottom) - 4;
            const points = [];

            for (let y = top; y < bottom; y += 12) {
                const el = document.elementFromPoint(box.left + box.width / 2, y);
                points.push(Boolean(el && menu.contains(el)));
            }

            return { overlap: bottom - top, points };
        });

        // The precondition: the menu does reach down over the toolbar.
        expect(cover.overlap).toBeGreaterThan(20);
        expect(cover.points.every(Boolean)).toBe(true);
    });

    test('the ends of a level disable the direction that would leave it', async ({ page }) => {
        await openLevel(page);

        await page.locator('.folderfolio-row', { hasText: 'Alpha' }).first().click();
        await page.locator('.folderfolio-row__menu').click();
        await expect(page.getByRole('menuitem', { name: /move up/i })).toBeDisabled();
        await expect(page.getByRole('menuitem', { name: /move down/i })).toBeEnabled();
        await page.keyboard.press('Escape');

        await page.locator('.folderfolio-row', { hasText: 'Charlie' }).first().click();
        await page.locator('.folderfolio-row__menu').click();
        await expect(page.getByRole('menuitem', { name: /move up/i })).toBeEnabled();
        await expect(page.getByRole('menuitem', { name: /move down/i })).toBeDisabled();
    });

    /**
     * The ⋮, and the two properties that make it affordable and correct.
     *
     * Affordable: one button in a tree that is not virtualised and whose rows
     * are not memoised. Correct: it is on the selected row, so it and the
     * narrow width's More can never aim at different folders.
     *
     * The third assertion is the one a screenshot would not catch — the name
     * track must be the same width on a selected row as on an unselected one,
     * because the space is reserved on every row and only the glyph is
     * conditional. Reserve it per row instead and selecting a folder reflows
     * its name, which is the hover version's defect moved to a click.
     */
    test('the row menu is on the selected row, and only there', async ({ page }) => {
        await openLevel(page);

        await expect(page.locator('.folderfolio-row__menu')).toHaveCount(0);

        await page.locator('.folderfolio-row', { hasText: 'Alpha' }).first().click();

        const menus = page.locator('.folderfolio-row__menu');
        await expect(menus).toHaveCount(1);
        await expect(
            page.locator('.folderfolio-row[aria-selected="true"] .folderfolio-row__menu')
        ).toHaveCount(1);

        // Same name track, selected or not.
        const tracks = await page.evaluate(() => {
            const gap = (row: Element) => {
                const r = row.getBoundingClientRect();
                const n = row.querySelector('.folderfolio-row__name')!.getBoundingClientRect();

                return Math.round(r.right - n.right);
            };
            const rows = [...document.querySelectorAll('.folderfolio-tree .folderfolio-row')];

            return {
                selected: gap(rows.find((r) => r.getAttribute('aria-selected') === 'true')!),
                plain: gap(rows.find((r) => r.getAttribute('aria-selected') !== 'true')!),
            };
        });

        expect(tracks.selected).toBe(tracks.plain);

        await menus.click();

        const panel = page.locator('.folderfolio-menu--row');
        await expect(panel).toBeVisible();
        await expect(panel.getByRole('menuitem', { name: /^rename$/i })).toBeVisible();
        await expect(panel.getByRole('menuitem', { name: /move up/i })).toBeVisible();
        await expect(panel.getByRole('menuitem', { name: /move down/i })).toBeVisible();
        await expect(panel.getByRole('menuitem', { name: /^delete$/i })).toBeVisible();

        // No heading: the menu is physically on the folder it acts on.
        await expect(panel.locator('.folderfolio-menu__head')).toHaveCount(0);
    });
});

/**
 * Sorting inside one folder — tier 1 item 2.
 *
 * The two assertions that matter are both about scope. A folder's order must
 * reach its own children, and it must reach *nothing else*: not its sibling,
 * not the level it sits on, and not its grandchildren, which are inside a
 * different folder and have their own answer.
 */
test.describe('a folder sorts what is inside it', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/wp-admin/upload.php');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);

        const outer = await createFolder(page, 'Outer');
        for (const name of ['Zulu', 'Alpha', 'Mike']) {
            await createFolder(page, name, outer.id);
        }

        // A sibling with children of its own, so "does not leak" has
        // something to be true about.
        const other = await createFolder(page, 'Other');
        for (const name of ['Zebra', 'Ant']) {
            await createFolder(page, name, other.id);
        }

        await page.reload();
        await waitForTree(page, 'Outer');
    });

    const childrenOf = (page: import('@playwright/test').Page, parent: string) =>
        page.evaluate((name) => {
            const rows = [...document.querySelectorAll('[data-folderfolio-name]')];
            const at = rows.findIndex((r) => r.getAttribute('data-folderfolio-name') === name);

            return rows
                .slice(at + 1)
                .filter((r) => r.getAttribute('data-folderfolio-depth') === '1')
                .map((r) => r.getAttribute('data-folderfolio-name')!)
                .slice(0, 3);
        }, parent);

    async function openSortStep(page: import('@playwright/test').Page, folder: string) {
        await page.locator('.folderfolio-row', { hasText: folder }).first().click();
        await page.locator('.folderfolio-row__menu').click();
        await page.getByRole('menuitem', { name: /subfolders/i }).click();
    }

    test('the panel says what each scope is set to before it is opened', async ({ page }) => {
        await page.locator('.folderfolio-row', { hasText: 'Outer' }).first().click();
        await page.locator('.folderfolio-row__menu').click();

        const panel = page.locator('.folderfolio-menu--row');

        await expect(panel.locator('.folderfolio-menu__label')).toHaveText(/sort inside/i);

        // Both rows, both unset — which is a value, not an absence. The
        // step rows only: the Gallery row (tier 3 item 14) has a value too,
        // and Colour (24 Sep) is a step with nothing to say when unset.
        const values = panel.locator('.folderfolio-menu__item--step .folderfolio-menu__value');
        await expect(values).toHaveCount(3);
        await expect(values.nth(0)).toHaveText('');
        await expect(values.nth(1)).toHaveText(/same as everywhere/i);
        await expect(values.nth(2)).toHaveText(/same as everywhere/i);
    });

    // Nick, 24 Sep: "make color a nested child as to reach delete you should
    // scroll". The ten swatches were ~130px of the first step.
    test('colour is a second step, and Delete is on the first screen', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.locator('.folderfolio-row', { hasText: 'Outer' }).first().click();
        await page.locator('.folderfolio-row__menu').click();

        const panel = page.locator('.folderfolio-menu--row');
        await expect(panel.locator('.folderfolio-swatches')).toHaveCount(0);

        const fits = await panel.evaluate((el) => {
            const del = [...el.querySelectorAll('button')].find((b) => /delete/i.test(b.textContent ?? ''))!;
            const menu = el.getBoundingClientRect();
            const row = del.getBoundingClientRect();

            return { scrolls: el.scrollHeight > el.clientHeight, inside: row.bottom <= menu.bottom + 0.5, height: menu.height };
        });
        expect(fits.scrolls).toBe(false);
        expect(fits.inside).toBe(true);

        await panel.getByRole('menuitem', { name: /^colour/i }).click();
        await expect(panel.locator('.folderfolio-swatches__swatch')).toHaveCount(10);
        await expect(panel.getByRole('menuitemradio', { name: /no colour/i })).toHaveAttribute('aria-checked', 'true');
        await panel.getByRole('menuitemradio', { name: /^plum$/i }).click();
        await expect(panel).toHaveCount(0);

        await page.locator('.folderfolio-row__menu').click();
        const colour = page.locator('.folderfolio-menu--row').getByRole('menuitem', { name: /^colour/i });
        await expect(colour.locator('.folderfolio-menu__value')).toHaveText(/plum/i);
        await expect(colour.locator('.folderfolio-menu__chip')).toHaveCount(1);

        // Back returns focus to the row it left from.
        await colour.click();
        await page.locator('.folderfolio-menu__back').click();
        await expect(colour).toBeFocused();
    });

    test('an order reaches that folder\'s children and nothing else', async ({ page }) => {
        await page
            .locator('.folderfolio-row', { hasText: 'Outer' })
            .first()
            .locator('.folderfolio-row__switcher')
            .click();
        await page
            .locator('.folderfolio-row', { hasText: 'Other' })
            .first()
            .locator('.folderfolio-row__switcher')
            .click();

        await openSortStep(page, 'Outer');
        await page.getByRole('menuitemradio', { name: /name, z to a/i }).click();

        await expect.poll(() => childrenOf(page, 'Outer')).toEqual(['Zulu', 'Mike', 'Alpha']);

        // The sibling's children are untouched — they follow the global sort,
        // whatever it happens to be, and are not reversed.
        await expect.poll(() => childrenOf(page, 'Other')).not.toEqual(['Zebra', 'Ant']);
    });

    test('Same as everywhere puts it back', async ({ page }) => {
        await page
            .locator('.folderfolio-row', { hasText: 'Outer' })
            .first()
            .locator('.folderfolio-row__switcher')
            .click();

        await openSortStep(page, 'Outer');
        await page.getByRole('menuitemradio', { name: /name, z to a/i }).click();
        await expect.poll(() => childrenOf(page, 'Outer')).toEqual(['Zulu', 'Mike', 'Alpha']);

        await openSortStep(page, 'Outer');
        await page.getByRole('menuitemradio', { name: /same as everywhere/i }).click();

        // Not the reversed order any more. Which order it *is* depends on the
        // site default, and asserting that here would be asserting the
        // fixture rather than the feature.
        await expect.poll(() => childrenOf(page, 'Outer')).not.toEqual(['Zulu', 'Mike', 'Alpha']);
    });
});

/**
 * Cut, copy and paste — tier 1 item 5.
 *
 * Each guard fails on one reversion: a copy that drops a property, a copy
 * that brings files it was not asked for (or leaves them when it was), a cut
 * row that stops saying so, a paste row that stays pressable where it cannot
 * work, and a menu that opens upwards as a 10px sliver — which the row menu
 * did, unremarked, from `abbd720` until 23 Sep.
 */
test.describe('cut, copy and paste', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/wp-admin/upload.php');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);

        const brand = await createFolder(page, 'Brand');
        const logos = await createFolder(page, 'Logos', brand.id);
        await createFolder(page, 'Primary', logos.id);
        await createFolder(page, 'Acme');

        await page.evaluate(async (id) => {
            await window.wp.apiFetch({
                path: `/folderfolio/v1/folders/${id}`,
                method: 'PATCH',
                data: { color: 'red' },
            });
            await window.wp.apiFetch({
                path: `/folderfolio/v1/folders/${id}/sort`,
                method: 'POST',
                data: { scope: 'files', order: 'name-desc' },
            });
        }, logos.id);

        await page.reload();
        await waitForTree(page, 'Brand');
    });

    async function menuOn(page: import('@playwright/test').Page, folder: string) {
        await page.locator('.folderfolio-tree .folderfolio-row', { hasText: new RegExp(`^${folder}`) }).first().click();
        await page.locator('.folderfolio-row__menu').click();
    }

    /** The server's own tree, flattened to name/colour/order lines. */
    const shape = (page: import('@playwright/test').Page) =>
        page.evaluate(async () => {
            const response = await window.wp.apiFetch({ path: '/folderfolio/v1/folders' });
            const out: string[] = [];
            const walk = (nodes: any[], depth: number) =>
                nodes.forEach((node) => {
                    out.push(`${'  '.repeat(depth)}${node.name}|${node.color ?? ''}|${node.sort_files ?? ''}`);
                    walk(node.children ?? [], depth + 1);
                });
            walk(response.data, 0);

            return out.sort();
        });

    test('Copy, then Paste beside, makes “Brand copy” with every property below it', async ({ page }) => {
        await menuOn(page, 'Brand');
        await page.getByRole('menuitem', { name: 'Copy', exact: true }).click();
        await menuOn(page, 'Brand');
        await page.getByRole('menuitem', { name: 'Beside this folder' }).click();

        await expect.poll(() => shape(page)).toContain('Brand copy||');
        const lines = await shape(page);

        // Two Logos, both red and both Z–A on files; two Primary.
        expect(lines.filter((l) => l.trim() === 'Logos|red|name-desc')).toHaveLength(2);
        expect(lines.filter((l) => l.trim().startsWith('Primary|'))).toHaveLength(2);
    });

    test('Copy with files files the same media; plain Copy files none', async ({ page }) => {
        const ids = await page.evaluate(async () => {
            const media = await window.wp.apiFetch({ path: '/wp/v2/media?per_page=2&_fields=id' });
            const tree = await window.wp.apiFetch({ path: '/folderfolio/v1/folders' });
            const logos = tree.data[0].name === 'Brand'
                ? tree.data[0].children[0]
                : tree.data[1].children[0];
            await window.wp.apiFetch({
                path: '/folderfolio/v1/assignments',
                method: 'POST',
                data: { folder_id: logos.id, attachment_ids: media.map((m: any) => m.id), mode: 'add' },
            });

            return media.map((m: any) => m.id);
        });

        test.skip(ids.length === 0, 'the rig has no media to file');

        for (const [item, expected] of [['Copy with files', ids.length], ['Copy', 0]] as const) {
            await menuOn(page, 'Brand');
            await page.getByRole('menuitem', { name: item, exact: true }).click();
            await menuOn(page, 'Acme');
            await page.getByRole('menuitem', { name: 'Inside this folder' }).click();

            await expect
                .poll(() =>
                    page.evaluate(async () => {
                        const tree = (await window.wp.apiFetch({ path: '/folderfolio/v1/folders' })).data;
                        const acme = tree.find((n: any) => n.name === 'Acme');
                        const newest = [...acme.children].sort((a: any, b: any) => b.id - a.id)[0];
                        const logos = newest?.children?.[0];

                        return logos
                            ? (await window.wp.apiFetch({ path: `/folderfolio/v1/folders/${logos.id}/attachments` })).data.count
                            : -1;
                    })
                )
                .toBe(expected);
        }
    });

    test('a cut folder is drawn cut until it is pasted, and Escape lets go of it', async ({ page }) => {
        await menuOn(page, 'Brand');
        await page.getByRole('menuitem', { name: 'Cut' }).click();

        const brand = page.locator('.folderfolio-tree .folderfolio-row', { hasText: /^Brand/ }).first();
        await expect(brand).toHaveClass(/is-cut/);
        // The name keeps its ink — a cut row is still a live control.
        await expect(brand.locator('.folderfolio-row__name')).toHaveCSS('opacity', '1');

        await brand.focus();
        await page.keyboard.press('Escape');
        await expect(brand).not.toHaveClass(/is-cut/);
    });

    test('Paste inside a folder moves it there and opens the destination', async ({ page }) => {
        await menuOn(page, 'Brand');
        await page.getByRole('menuitem', { name: 'Cut' }).click();
        await menuOn(page, 'Acme');
        await page.getByRole('menuitem', { name: 'Inside this folder' }).click();

        const acme = page.locator('.folderfolio-tree .folderfolio-row', { hasText: /^Acme/ }).first();
        await expect(acme).toHaveAttribute('aria-expanded', 'true');
        await expect(page.locator('.folderfolio-row.is-cut')).toHaveCount(0);
    });

    test('a folder cannot be pasted inside itself or below itself', async ({ page }) => {
        await menuOn(page, 'Brand');
        await page.getByRole('menuitem', { name: 'Copy', exact: true }).click();
        await menuOn(page, 'Brand');

        await expect(page.getByRole('menuitem', { name: 'Inside this folder' })).toBeDisabled();
        // Beside itself is the ordinary duplicate, and stays available.
        await expect(page.getByRole('menuitem', { name: 'Beside this folder' })).toBeEnabled();
    });

    // A row menu that fits the window is shown whole — Delete included —
    // above its row, below it, or slid over it when neither side has the
    // room (Nick, 24 Sep: "to reach delete you should scroll"). Until then it
    // flipped at the 320px threshold and scrolled inside whichever side won.
    test('the row menu opened near the bottom of a short window is shown whole', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 520 });
        await page.reload();
        await waitForTree(page, 'Brand');

        // Brand is the last root under Name, A to Z (the sort a reload comes
        // back to); in a 520px window neither side of it holds the menu.
        await menuOn(page, 'Brand');

        const geometry = await page.evaluate(() => {
            const menu = document.querySelector('.folderfolio-menu--row') as HTMLElement;
            const del = [...menu.querySelectorAll('button')].find((b) => /delete/i.test(b.textContent ?? ''))!;
            const m = menu.getBoundingClientRect();

            return {
                position: getComputedStyle(menu).position,
                top: m.top,
                bottom: m.bottom,
                scrolls: menu.scrollHeight > menu.clientHeight,
                deleteInside: del.getBoundingClientRect().bottom <= m.bottom + 0.5,
            };
        });

        expect(geometry.position).toBe('fixed');
        expect(geometry.top).toBeGreaterThanOrEqual(8);
        expect(geometry.bottom).toBeLessThanOrEqual(520 - 8 + 0.5);
        expect(geometry.scrolls).toBe(false);
        expect(geometry.deleteInside).toBe(true);

        // A second step is shorter, and it is placed again: against its row,
        // not left where the first step had to slide to (Nick, 24 Sep — the
        // Subfolders step floated at the top of the window).
        await page.locator('.folderfolio-menu--row').getByRole('menuitem', { name: /subfolders/i }).click();
        await expect(page.locator('.folderfolio-menu__back')).toBeVisible();

        const step = await page.evaluate(() => {
            const menu = document.querySelector('.folderfolio-menu--row')!.getBoundingClientRect();
            const trigger = document.querySelector('[aria-selected="true"] .folderfolio-row__menu')!.getBoundingClientRect();

            return { below: Math.abs(menu.top - (trigger.bottom + 2)), above: Math.abs(menu.bottom - (trigger.top - 2)) };
        });

        expect(Math.min(step.below, step.above)).toBeLessThan(1);
    });
});

/**
 * Three gaps closed on 23 Sep, each with the guard that fails if it reopens.
 */
test.describe('what the rail says after a write', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/wp-admin/upload.php?mode=list');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);
        await createFolder(page, 'Alpha');
        await createFolder(page, 'Bravo');
        await page.reload();
        await waitForTree(page, 'Alpha');
    });

    test('a refused write is on screen, in the server\'s own words, until dismissed', async ({ page }) => {
        await page.locator('.folderfolio-tree .folderfolio-row', { hasText: /^Alpha/ }).first().click();
        await page.locator('.folderfolio-row__menu').click();
        await page.getByRole('menuitem', { name: 'Rename' }).click();
        await page.locator('.folderfolio-row__input').fill('Bravo');
        await page.keyboard.press('Enter');

        const notice = page.locator('.folderfolio-toast--notice');
        await expect(notice).toHaveAttribute('role', 'alert');
        await expect(notice).toContainText('already exists');

        await notice.getByRole('button', { name: 'Dismiss' }).click();
        await expect(notice).toHaveCount(0);
    });

    test('the list view\'s folder select learns a folder created after the page loaded', async ({ page }) => {
        const select = page.locator('select[name="folderfolio_folder"]');
        await expect(select).toHaveCount(1);

        await page.getByRole('button', { name: /new folder/i }).click();
        await page.locator('.folderfolio-row__input').fill('Charlie');
        await page.keyboard.press('Enter');

        await page.locator('.folderfolio-tree .folderfolio-row', { hasText: /^Charlie/ }).first().click();

        // The option exists, it is selected, and the form would submit it —
        // before, the select kept showing the previous folder and core's
        // Filter button sent that one.
        const id = await page.evaluate(() => new URL(location.href).searchParams.get('folderfolio_folder'));
        await expect(select).toHaveValue(id!);
        expect(
            await select.evaluate((el: HTMLSelectElement) => new FormData(el.form!).get('folderfolio_folder'))
        ).toBe(id);
    });

    test('a file filed in two sibling folders counts once in their parent', async ({ page }) => {
        const counts = await page.evaluate(async () => {
            const make = async (name: string, parent: number | null) =>
                (await window.wp.apiFetch({ path: '/folderfolio/v1/folders', method: 'POST', data: { name, parent_id: parent } })).data.id;
            const media = await window.wp.apiFetch({ path: '/wp/v2/media?per_page=1&_fields=id' });

            if (media.length === 0) {
                return null;
            }

            const parent = await make('Clients', null);
            for (const name of ['One', 'Two']) {
                const id = await make(name, parent);
                await window.wp.apiFetch({
                    path: '/folderfolio/v1/assignments',
                    method: 'POST',
                    data: { folder_id: id, attachment_ids: [media[0].id], mode: 'add' },
                });
            }

            const tree = (await window.wp.apiFetch({ path: '/folderfolio/v1/folders?counts=inherited' })).data;

            return tree.find((n: any) => n.id === parent).total_count;
        });

        test.skip(counts === null, 'the rig has no media to file');
        expect(counts).toBe(1);
    });
});

test.describe('the undo toast', () => {
    test('counts only the files that end up in no folder, in the right form', async ({ page }) => {
        await page.goto('/wp-admin/upload.php');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);

        const media = await page.evaluate(async () =>
            (await window.wp.apiFetch({ path: '/wp/v2/media?per_page=2&_fields=id' })).map((m: any) => m.id)
        );
        test.skip(media.length < 2, 'the rig needs two media items');

        const one = await createFolder(page, 'One');
        const two = await createFolder(page, 'Two');
        await page.evaluate(
            async ([a, b, ids]) => {
                const file = (folder: number, list: number[]) =>
                    window.wp.apiFetch({
                        path: '/folderfolio/v1/assignments',
                        method: 'POST',
                        data: { folder_id: folder, attachment_ids: list, mode: 'add' },
                    });
                await file(a, ids);
                await file(b, [ids[0]]);
            },
            [one.id, two.id, media] as const
        );

        await page.reload();
        await waitForTree(page, 'One');

        const toastText = () => page.locator('.folderfolio-toast:not(.folderfolio-toast--notice) .folderfolio-toast__text');

        // Two's only file is also in One: nothing moves to Unassigned.
        await page.locator('.folderfolio-tree .folderfolio-row', { hasText: /^Two/ }).first().click();
        await page.locator('.folderfolio-row__menu').click();
        await page.getByRole('menuitem', { name: 'Delete' }).click();
        await expect(toastText()).toHaveText('Deleted “Two”');
        await page.getByRole('button', { name: 'Undo' }).click();

        // One holds two files, one of them also in Two: exactly one moves, singular.
        await page.locator('.folderfolio-tree .folderfolio-row', { hasText: /^One/ }).first().click();
        await page.locator('.folderfolio-row__menu').click();
        await page.getByRole('menuitem', { name: 'Delete' }).click();
        await expect(toastText()).toHaveText('Deleted “One” — 1 file moved to Unassigned');
    });
});
