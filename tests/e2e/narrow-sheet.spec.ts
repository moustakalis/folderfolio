import { expect, test, type Page } from '@playwright/test';

import { createFolder, resetFolders, waitForTree } from './helpers/folders';

/**
 * The rail's one scroller — Nick, 25 Sep, option A on board
 * NF7bQktuksgBSi4rCfoLvf.
 *
 * Starred and Smart sat in the fixed chrome above the search line, so every
 * row in them came out of the folder list: at a 669px-tall phone the sheet
 * left the folders 31.8px with one star and one smart folder, and none with
 * five of each; the wide rail at 720 was down to 14px. Now Starred, Smart, the
 * search line and the folders are one scroller, the search line sticks at its
 * top and *Top level* beneath it, and the two groups' own 5-row scrollers are
 * gone.
 *
 * The guard: with five stars and five smart folders at 669px tall, the folder
 * list has at least four rows under the pinned search line, in both
 * renderers — and the rail has exactly one scroller.
 */

const TALL = 669;

async function seed(page: Page): Promise<{ folders: number[]; smart: number[] }> {
    await resetFolders(page);
    const folders: number[] = [];

    for (let i = 1; i <= 24; i += 1) {
        folders.push((await createFolder(page, `Sheet ${String(i).padStart(2, '0')}`)).id);
    }

    const smart = await page.evaluate(async (starred) => {
        const wp = (window as any).wp;
        await wp.apiFetch({ path: '/folderfolio/v1/preferences', method: 'POST', data: { rail: { stars: [] } } });

        for (const id of starred) {
            await wp.apiFetch({ path: `/folderfolio/v1/folders/${id}/star`, method: 'POST', data: { starred: true } });
        }

        const made: number[] = [];

        for (let i = 1; i <= 5; i += 1) {
            made.push(
                (
                    await wp.apiFetch({
                        path: '/folderfolio/v1/smart',
                        method: 'POST',
                        data: { name: `Sheet smart ${i}`, rules: [{ field: 'type', op: 'is', value: 'image' }] },
                    })
                ).data.id as number
            );
        }

        return made;
    }, folders.slice(0, 5));

    return { folders, smart };
}

async function unseed(page: Page, smart: number[]): Promise<void> {
    await page.evaluate(async (ids) => {
        const wp = (window as any).wp;

        for (const id of ids) {
            await wp.apiFetch({ path: `/folderfolio/v1/smart/${id}`, method: 'DELETE' }).catch(() => undefined);
        }

        await wp.apiFetch({ path: '/folderfolio/v1/preferences', method: 'POST', data: { rail: { stars: [] } } });
    }, smart);
    await resetFolders(page);
}

/** What the pinned search line leaves for folders, scrolled to them. */
async function folderRoom(page: Page) {
    return page.evaluate(() => {
        const rail = document.querySelector('.folderfolio-rail')!;
        const scroller = document.querySelector<HTMLElement>('.folderfolio-rail__scroll')!;
        const controls = document.querySelector<HTMLElement>('.folderfolio-rail__controls')!;
        // To the end: the line is pinned and the list runs past the bottom.
        scroller.scrollTop = scroller.scrollHeight;
        scroller.dispatchEvent(new Event('scroll'));

        const rowH = parseFloat(getComputedStyle(rail).getPropertyValue('--ff-row-h'));
        const room = scroller.getBoundingClientRect().bottom - controls.getBoundingClientRect().bottom;
        // Declared, not merely overflowing: a group capped at five rows with
        // five in it does not overflow, and would still be a scroller inside
        // the scroller the moment a sixth star arrived.
        const scrollers = [...rail.querySelectorAll('*')].filter((el) => {
            const y = getComputedStyle(el).overflowY;

            return y === 'auto' || y === 'scroll';
        }).length;

        return {
            rows: room / rowH,
            pinned: controls.getBoundingClientRect().top - scroller.getBoundingClientRect().top,
            starred: document.querySelectorAll('.folderfolio-rail__starred .folderfolio-rail__fixed-row').length,
            smart: document.querySelectorAll('.folderfolio-rail__smart-row').length,
            scrollers,
        };
    });
}

test.describe('the rail is one scroller', () => {
    test.setTimeout(120_000);

    test('wide: five stars and five smart folders leave the folders their room', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: TALL });
        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        const seeded = await seed(page);

        try {
            await page.reload();
            await waitForTree(page, 'Sheet 24');
            const room = await folderRoom(page);

            // The precondition: both groups are full.
            expect(room.starred).toBe(5);
            expect(room.smart).toBe(5);
            expect(room.pinned).toBeCloseTo(0, 0);
            expect(room.rows).toBeGreaterThanOrEqual(4);
            expect(room.scrollers).toBe(1);
        } finally {
            await unseed(page, seeded.smart);
        }
    });

    test('narrow: the sheet keeps four folder rows under the pinned search line', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        const seeded = await seed(page);

        try {
            await page.setViewportSize({ width: 596, height: TALL });
            await page.reload();
            await page.locator('.folderfolio-rail__tab').click();
            await page.locator('.folderfolio-levels__row', { hasText: 'Sheet 24' }).waitFor();
            const room = await folderRoom(page);

            expect(room.starred).toBe(5);
            expect(room.smart).toBe(5);
            expect(room.pinned).toBeCloseTo(0, 0);
            expect(room.rows).toBeGreaterThanOrEqual(4);
            expect(room.scrollers).toBe(1);
        } finally {
            await page.setViewportSize({ width: 1280, height: 900 });
            await unseed(page, seeded.smart);
        }
    });

    // The control line's menus live on the body now (AnchoredMenu): inside
    // the sticky band they would be clipped by the scroller.
    test('the Sort menu opens whole, from its button, on the body', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: TALL });
        await page.goto('/wp-admin/upload.php?mode=grid');
        await resetFolders(page);
        await createFolder(page, 'Sort check');
        await page.reload();
        await waitForTree(page, 'Sort check');

        await page.getByRole('button', { name: 'Sort', exact: true }).click();
        const menu = page.locator('.folderfolio-menu--anchored');
        await expect(menu).toBeVisible();

        const box = await menu.evaluate((el) => {
            const b = el.getBoundingClientRect();
            const button = document.querySelector('.folderfolio-rail button[aria-label="Sort"]')!.getBoundingClientRect();

            return {
                onBody: el.parentElement === document.body,
                below: b.top - button.bottom,
                endAligned: Math.round(b.right - button.right),
                whole: el.scrollHeight <= el.clientHeight,
            };
        });

        expect(box.onBody).toBe(true);
        expect(box.below).toBeGreaterThanOrEqual(0);
        expect(box.endAligned).toBe(0);
        expect(box.whole).toBe(true);

        await page.keyboard.press('Escape');
        await expect(menu).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Sort', exact: true })).toBeFocused();
        await resetFolders(page);
    });
});
