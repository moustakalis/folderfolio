import { expect, test, type Page } from '@playwright/test';

import { createFolder, resetFolders } from './helpers/folders';

/**
 * The gallery block, on the front end, in every theme this WordPress has.
 *
 * Theme compatibility is the one thing in phase 8 that cannot be reasoned
 * about: the plugin's front-end stylesheet is 782 bytes and carries no colour,
 * no typography and no spacing of its own, precisely so that the theme owns
 * the page — and whether that worked is a question about somebody else's CSS.
 * So it is asked here, against the themes that are actually installed, rather
 * than eyeballed once on one site.
 *
 * What each theme has to survive: the list renders, it has as many items as
 * the folder has images, the grid really is a grid with the right number of
 * tracks, the images have width, and nothing pushes the document sideways —
 * a horizontal scrollbar on a phone is the classic way a gallery meets a
 * theme's box model and loses.
 *
 * The shortcode is on the same page, so the classic-theme path is covered by
 * construction. A real page builder is not: Elementor is 10MB and belongs on
 * the development site, where this was verified by hand, not in a suite that
 * has to boot from nothing.
 */

const PAGE_TITLE = 'FolderFolio gallery harness';

/** The themes worth naming, if this WordPress happens to carry them. */
const KNOWN = ['twentytwentyfive', 'twentytwentyfour', 'twentytwentythree', 'twentytwentyone'];

interface Harness {
    folderId: number;
    images: number[];
    pageId: number;
    pageLink: string;
}

/**
 * A folder, three images in it, and a published page that shows them twice —
 * once through the block and once through the shortcode.
 */
async function seed(page: Page): Promise<Harness> {
    const folder = await createFolder(page, 'Gallery');

    const images = await page.evaluate(async (folderId) => {
        // 1x1 transparent PNGs: the smallest thing the media library accepts,
        // and no thumbnails worth waiting for.
        const bytes = Uint8Array.from(
            atob(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
            ),
            (c) => c.charCodeAt(0)
        );

        const ids: number[] = [];

        for (let i = 1; i <= 3; i++) {
            const form = new FormData();
            form.append('file', new File([bytes], `folderfolio-gallery-${i}.png`, { type: 'image/png' }));

            const media = await window.wp.apiFetch({ path: '/wp/v2/media', method: 'POST', body: form });

            ids.push(media.id as number);
        }

        await window.wp.apiFetch({
            path: '/folderfolio/v1/attachments/assign',
            method: 'POST',
            data: { folder_id: folderId, attachment_ids: ids },
        });

        return ids;
    }, folder.id);

    const created = await page.evaluate(
        async ([folderId, title]) => {
            const content = [
                `<!-- wp:folderfolio/gallery {"folderIds":[${folderId}],"columns":3} /-->`,
                '<!-- wp:shortcode -->',
                '[folderfolio_gallery folder="Gallery" columns="2" layout="masonry"]',
                '<!-- /wp:shortcode -->',
            ].join('\n\n');

            const post = await window.wp.apiFetch({
                path: '/wp/v2/pages',
                method: 'POST',
                data: { title, status: 'publish', content },
            });

            return { id: post.id as number, link: post.link as string };
        },
        [folder.id, PAGE_TITLE] as const
    );

    return { folderId: folder.id, images, pageId: created.id, pageLink: created.link };
}

async function tearDown(page: Page, harness: Harness): Promise<void> {
    await page.evaluate(async ([pageId, images]) => {
        await window.wp.apiFetch({ path: `/wp/v2/pages/${pageId}?force=true`, method: 'DELETE' });

        for (const id of images as number[]) {
            await window.wp.apiFetch({ path: `/wp/v2/media/${id}?force=true`, method: 'DELETE' });
        }
    }, [harness.pageId, harness.images] as const);

    await resetFolders(page);
}

/** Which themes this install has, and which one is on. */
async function themes(page: Page): Promise<{ installed: string[]; active: string }> {
    return page.evaluate(async () => {
        const all = await window.wp.apiFetch({ path: '/wp/v2/themes?status=active,inactive' });

        return {
            installed: all.map((theme: { stylesheet: string }) => theme.stylesheet),
            active: (all.find((theme: { status: string }) => 'active' === theme.status) ?? all[0])
                .stylesheet as string,
        };
    });
}

/**
 * Switch themes the way a person does.
 *
 * There is no core REST route for activating a theme, so this is the admin
 * screen's own link — nonce and all — which is also the path a site owner
 * takes.
 */
async function activate(page: Page, stylesheet: string): Promise<void> {
    await page.goto('/wp-admin/themes.php');

    const link = page.locator(`a[href*="action=activate"][href*="stylesheet=${stylesheet}"]`).first();

    if ((await link.count()) === 0) {
        // Already active.
        return;
    }

    await link.click();
    await page.waitForURL(/themes\.php/);
}

test.describe('the gallery block, theme by theme', () => {
    test('renders in every installed theme without pushing the page sideways', async ({ page }) => {
        // Three themes × a page load each, on php-wasm.
        test.setTimeout(180_000);

        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);

        const harness = await seed(page);
        const { installed, active } = await themes(page);
        const toCheck = KNOWN.filter((slug) => installed.includes(slug));

        // Printed, not assumed: which themes a WordPress ships changes with
        // the release, and a compatibility test that silently checked one
        // theme would be worth less than it claims.
        console.log(
            `gallery: checking ${toCheck.length} of ${installed.length} installed themes — ${toCheck.join(', ')}`
        );

        expect(toCheck.length, 'at least one known theme is installed').toBeGreaterThan(0);

        try {
            for (const theme of toCheck) {
                await activate(page, theme);
                await page.goto(harness.pageLink);

                const lists = page.locator('ul.wp-block-folderfolio-gallery');

                await expect(lists, `${theme}: both galleries render`).toHaveCount(2);

                const block = lists.first();

                await expect(block.locator('li'), `${theme}: three images`).toHaveCount(3);

                const layout = await block.evaluate((node) => {
                    const style = getComputedStyle(node);

                    return {
                        display: style.display,
                        tracks: style.gridTemplateColumns.split(' ').filter(Boolean).length,
                        overflow: document.documentElement.scrollWidth
                            - document.documentElement.clientWidth,
                        imageWidth: node.querySelector('img')?.getBoundingClientRect().width ?? 0,
                    };
                });

                expect(layout.display, `${theme}: the list is a grid`).toBe('grid');
                expect(layout.tracks, `${theme}: three columns`).toBe(3);
                expect(layout.imageWidth, `${theme}: the images have width`).toBeGreaterThan(0);

                // One pixel of slack: sub-pixel rounding in a theme's own
                // container is not a horizontal scrollbar.
                expect(layout.overflow, `${theme}: no horizontal overflow`).toBeLessThanOrEqual(1);

                const masonry = lists.nth(1);

                await expect(
                    masonry,
                    `${theme}: the shortcode's masonry variant`
                ).toHaveClass(/folderfolio-gallery--masonry/);

                expect(
                    await masonry.evaluate((node) => getComputedStyle(node).columnCount),
                    `${theme}: masonry columns`
                ).toBe('2');
            }
        } finally {
            await activate(page, active);
            await page.goto('/wp-admin/upload.php?mode=grid');
            await tearDown(page, harness);
        }
    });
});
