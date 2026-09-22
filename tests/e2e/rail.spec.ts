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

        await page.locator('.folderfolio-row', { hasText: 'Archive' }).click();
        await page.getByRole('button', { name: /^delete$/i }).click();

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

        const clear = page.locator('.folderfolio-crumbs__clear');

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

        await expect(page).not.toHaveURL(/folderfolio_folder=/);
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
        page.evaluate(() =>
            [...document.querySelectorAll('[data-folderfolio-name]')]
                .map((el) => el.getAttribute('data-folderfolio-name'))
                .filter((name): name is string => /^(Alpha|Bravo|Charlie)$/.test(name ?? ''))
        );

    async function openLevel(page: import('@playwright/test').Page) {
        await page
            .locator('.folderfolio-row', { hasText: 'Level' })
            .locator('.folderfolio-row__switcher')
            .click();
    }

    test('Alt+ArrowDown moves a folder one place and selects Custom order', async ({ page }) => {
        await openLevel(page);
        await page.locator('.folderfolio-row', { hasText: 'Alpha' }).first().focus();
        await page.keyboard.press('Alt+ArrowDown');

        await expect.poll(() => arrangement(page)).toEqual(['Bravo', 'Alpha', 'Charlie']);

        // The arrangement exists in sort_order whatever the sort; it is only
        // *visible* under Custom. A gesture that saved one and left the tree
        // showing another order is the half-finished version of this feature.
        await page.getByRole('button', { name: /^sort$/i }).click();
        await expect(page.getByRole('menuitem', { name: /custom order/i })).toHaveAttribute(
            'aria-checked',
            'true'
        );
    });

    for (const width of [1280, 600]) {
        test(`More > Move down reorders at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 900 });

            if (width > 782) {
                await openLevel(page);
            } else {
                // The drill-down: there is no expand here, the row walks in.
                await page.locator('.folderfolio-levels__row', { hasText: 'Level' }).click();
            }

            // Alpha is a leaf, so tapping it selects and stays put — the case
            // where the moved folder and its siblings are both on screen.
            await page
                .locator('.folderfolio-row', { hasText: 'Alpha' })
                .first()
                .click();
            await page.getByRole('button', { name: /^more$/i }).click();
            await page.getByRole('menuitem', { name: /move down/i }).click();

            await expect.poll(() => arrangement(page)).toEqual(['Bravo', 'Alpha', 'Charlie']);
        });
    }

    test('the ends of a level disable the direction that would leave it', async ({ page }) => {
        await openLevel(page);

        await page.locator('.folderfolio-row', { hasText: 'Alpha' }).first().click();
        await page.getByRole('button', { name: /^more$/i }).click();
        await expect(page.getByRole('menuitem', { name: /move up/i })).toBeDisabled();
        await expect(page.getByRole('menuitem', { name: /move down/i })).toBeEnabled();
        await page.keyboard.press('Escape');

        await page.locator('.folderfolio-row', { hasText: 'Charlie' }).first().click();
        await page.getByRole('button', { name: /^more$/i }).click();
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

        // Both rows, both unset — which is a value, not an absence.
        await expect(panel.locator('.folderfolio-menu__value')).toHaveCount(2);
        await expect(panel.locator('.folderfolio-menu__value').first()).toHaveText(
            /same as everywhere/i
        );
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
