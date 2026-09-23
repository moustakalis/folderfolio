import { expect, test, type Page } from '@playwright/test';

import { createFolder, resetFolders, waitForTree } from './helpers/folders';

/**
 * Lock, pin and star — tier 2 item 10, board 3ZU8VGkJemznTvKp8tNnvY.
 *
 * Nick's answers: a lock protects a folder's shape and everything beneath it,
 * and stops everyone but the roles given Lock (Administrator by default);
 * pin is the site's and puts a folder first in its level; star is each
 * person's own and gathers their folders above the tree. The refusals
 * themselves are the server's and are asserted in FolderLocksTest; here is
 * what a person sees.
 */

async function seed(page: Page) {
    await page.goto('/wp-admin/upload.php?mode=grid');
    await page.locator('#folderfolio-rail').waitFor();
    await resetFolders(page);

    const parent = await createFolder(page, 'Marked');
    const alpha = await createFolder(page, 'Alpha', parent.id);
    const bravo = await createFolder(page, 'Bravo', parent.id);
    const inner = await createFolder(page, 'Bravo inner', bravo.id);
    const charlie = await createFolder(page, 'Charlie', parent.id);

    return { parent, alpha, bravo, inner, charlie };
}

async function expand(page: Page, name: string) {
    const row = page.locator('.folderfolio-tree [role="treeitem"]', { hasText: new RegExp(`^${name}`) }).first();

    await row.waitFor();

    if ((await row.getAttribute('aria-expanded')) !== 'true') {
        await row.locator('.folderfolio-row__switcher').click();
        await page.waitForFunction(
            (n) =>
                [...document.querySelectorAll('.folderfolio-tree [role="treeitem"]')].some(
                    (r) => r.getAttribute('aria-expanded') === 'true' && r.querySelector('.folderfolio-row__name')?.textContent === n
                ),
            name
        );
    }
}

async function openMenu(page: Page, name: string) {
    await page.locator('.folderfolio-tree .folderfolio-row', { hasText: new RegExp(`^${name}`) }).first().click();
    await page.locator('.folderfolio-row__menu').click();
}

async function children(page: Page, parent: string): Promise<string[]> {
    return page.evaluate((name) => {
        const rows = [...document.querySelectorAll<HTMLElement>('.folderfolio-tree [role="treeitem"]')];
        const at = rows.findIndex((r) => r.querySelector('.folderfolio-row__name')?.textContent === name);
        const level = Number(rows[at].getAttribute('aria-level'));
        const out: string[] = [];

        for (const row of rows.slice(at + 1)) {
            const l = Number(row.getAttribute('aria-level'));

            if (l <= level) break;
            if (l === level + 1) out.push(row.querySelector('.folderfolio-row__name')?.textContent ?? '');
        }

        return out;
    }, parent);
}

test.describe('lock, pin and star', () => {
    test.afterEach(async ({ page }) => {
        await page.evaluate(() =>
            window.wp.apiFetch({ path: '/folderfolio/v1/preferences', method: 'POST', data: { rail: { stars: [] } } })
        );
        await resetFolders(page);
    });

    test('a pinned folder leads its level, in the tree and in the cards', async ({ page }) => {
        const f = await seed(page);

        await page.goto(`/wp-admin/upload.php?mode=grid&folderfolio_folder=${f.parent.id}`);
        await waitForTree(page, 'Marked');
        await expand(page, 'Marked');

        // Bravo: the middle one by name and by age, so under any order the
        // rail can be in it has to move to come first.
        await openMenu(page, 'Bravo');
        const pin = page.getByRole('menuitemcheckbox', { name: 'Pin' });
        await expect(pin).toHaveAttribute('aria-checked', 'false');
        await pin.click();

        await expect.poll(async () => (await children(page, 'Marked'))[0]).toBe('Bravo');

        // Move up is refused across the line: the first unpinned folder
        // cannot step above the last pinned one.
        const second = (await children(page, 'Marked'))[1];
        await openMenu(page, second);
        await expect(page.getByRole('menuitem', { name: /move up/i })).toBeDisabled();
        await page.keyboard.press('Escape');

        // The cards under the filter row follow the rail.
        await page.locator('.folderfolio-tree .folderfolio-row', { hasText: /^Marked/ }).first().click();
        await expect(page.locator('.folderfolio-card').first()).toContainText('Bravo');
    });

    test('a star is mine, and gathers above the tree across a reload', async ({ page }) => {
        const f = await seed(page);

        await page.goto(`/wp-admin/upload.php?mode=grid&folderfolio_folder=${f.parent.id}`);
        await waitForTree(page, 'Marked');
        await expand(page, 'Marked');
        await expect(page.locator('.folderfolio-rail__starred')).toHaveCount(0);

        await openMenu(page, 'Bravo');
        // The group is drawn before the write lands (optimistic), so wait for
        // the write itself before reloading — or the reload races it.
        const written = page.waitForResponse((r) => r.url().includes(`/folders/${f.bravo.id}/star`));
        await page.getByRole('menuitemcheckbox', { name: 'Star' }).click();

        const group = page.getByRole('group', { name: 'Starred' });
        await expect(group).toContainText('Bravo');
        expect((await written).status()).toBe(200);

        await page.reload();
        await expect(page.getByRole('group', { name: 'Starred' })).toContainText('Bravo');

        // Choosing it filters to it.
        await page.getByRole('group', { name: 'Starred' }).getByRole('button').first().click();
        await expect(page).toHaveURL(new RegExp(`folderfolio_folder=${f.bravo.id}`));
    });

    test('a lock covers the folder and beneath it, and stops whoever lacks Lock', async ({ page }) => {
        const f = await seed(page);

        await page.goto(`/wp-admin/upload.php?mode=grid&folderfolio_folder=${f.parent.id}`);
        await waitForTree(page, 'Marked');
        await expand(page, 'Marked');

        await openMenu(page, 'Bravo');
        await page.getByRole('menuitemcheckbox', { name: 'Lock' }).click();

        await page.reload();
        await waitForTree(page, 'Marked');
        await expand(page, 'Marked');
        await expand(page, 'Bravo');

        const bravo = page.locator('.folderfolio-tree .folderfolio-row', { hasText: /^Bravo/ }).first();
        const inner = page.locator('.folderfolio-tree .folderfolio-row', { hasText: 'Bravo inner' });
        await expect(bravo.locator('.folderfolio-row__marks')).toContainText('locked');
        await expect(inner.locator('.folderfolio-row__inherited')).toHaveCount(1);

        // An administrator holds Lock, so nothing is disabled for them.
        await openMenu(page, 'Bravo inner');
        await expect(page.getByRole('menuitem', { name: /^rename$/i })).toBeEnabled();
        await expect(page.locator('.folderfolio-menu__why')).toHaveCount(0);
        await page.keyboard.press('Escape');

        // Someone without it — the config narrowed, as a role without Lock
        // would receive it — sees why, and the shape rows are off.
        await page.evaluate(() => {
            (window as unknown as { folderFolio: { can: Record<string, boolean> } }).folderFolio.can.lock = false;
        });
        await openMenu(page, 'Bravo inner');
        await expect(page.locator('.folderfolio-menu__why')).toContainText('“Bravo” is locked');
        await expect(page.getByRole('menuitem', { name: /^rename$/i })).toBeDisabled();
        await expect(page.getByRole('menuitem', { name: /^delete$/i })).toBeDisabled();
        await expect(page.getByRole('menuitem', { name: /^cut$/i })).toBeDisabled();
        await expect(page.getByRole('menuitem', { name: /^copy$/i })).toBeEnabled();
        await expect(page.getByRole('menuitemcheckbox', { name: 'Pin' })).toBeDisabled();
        await expect(page.getByRole('menuitemcheckbox', { name: 'Star' })).toBeEnabled();
        await expect(page.getByRole('menuitemcheckbox', { name: 'Lock' })).toHaveCount(0);
        await page.keyboard.press('Escape');

        // And the keyboard agrees with the menu.
        await page.locator('.folderfolio-tree .folderfolio-row', { hasText: 'Bravo inner' }).focus();
        await page.keyboard.press('F2');
        await expect(page.locator('.folderfolio-row__input')).toHaveCount(0);
    });

    /*
     * Nick, 23 Sep: the ⋮ is for every role, and each action is gated inside
     * it — an action the role does not hold is hidden, not greyed. An Author
     * (create + assign, the default) used to get no ⋮ at all, so could not
     * star. The config is narrowed the way the server narrows it for an
     * Author; the rig's one user is an administrator.
     */
    test('an Author has the ⋮, with Star and nothing they cannot do', async ({ page }) => {
        const f = await seed(page);

        await page.goto(`/wp-admin/upload.php?mode=grid&folderfolio_folder=${f.parent.id}`);
        await waitForTree(page, 'Marked');
        await expand(page, 'Marked');

        await page.evaluate(() => {
            const config = (window as unknown as { folderFolio: { can: Record<string, boolean> } }).folderFolio;
            // Download withheld here, so the menu is Star alone — the
            // Author's default Download row is download.spec.ts's.
            config.can = { create: true, rename: false, delete: false, assign: true, lock: false, download: false };
        });

        await openMenu(page, 'Charlie');

        const menu = page.locator('.folderfolio-menu--row');
        await expect(menu.getByRole('menuitemcheckbox', { name: 'Star' })).toBeVisible();
        // Hidden, not greyed: nothing else is in the menu at all.
        await expect(menu.locator('[role^="menuitem"]')).toHaveCount(1);

        await menu.getByRole('menuitemcheckbox', { name: 'Star' }).click();
        await expect(page.getByRole('group', { name: 'Starred' })).toContainText('Charlie');

        // Written through the one-folder route, so the server has it.
        const stars = await page.evaluate(async () => {
            const r = await window.wp.apiFetch({ path: '/folderfolio/v1/preferences' });

            return r.data.rail.stars as number[];
        });
        expect(stars).toEqual([f.charlie.id]);
    });
});
