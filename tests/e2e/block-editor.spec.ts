import { expect, test } from '@playwright/test';

import { createFolder, resetFolders } from './helpers/folders';

/**
 * The block editor, where two of our bundles share one page.
 *
 * The gallery block's editor script and the media picker's both write
 * `window.folderFolio`. Until 23 Sep each did it first-writer-wins, the
 * gallery's ran first, and its config — `create`, `rename`, `delete` all
 * false, meant for a read-only inspector tree — was what the picker read: an
 * administrator's picker had no ⋮ and no folder creation, its labels fell back
 * to English, and a directory dropped into it uploaded flat.
 *
 * Now every writer merges through Support\ClientConfig and states the user's
 * real abilities; the gallery narrows only its own bundle
 * (`restrictAbilities()`, lib/can.ts). Both halves are asserted: the picker
 * can, and the inspector still cannot.
 */

test.describe('the block editor', () => {
    test.setTimeout(120_000);

    test('the picker has the user’s abilities and every screen’s strings', async ({ page }) => {
        await page.goto('/wp-admin/post-new.php');
        await page.waitForFunction(() => Boolean((window as any).wp?.blocks && (window as any).folderFolio));

        const config = await page.evaluate(() => {
            const cfg = (window as any).folderFolio;

            return {
                can: cfg.can,
                // One string only the picker's writer carries, one only the
                // gallery's: the union is what proves nobody's were dropped.
                picker: 'uploadsGoToFolder' in cfg.i18n,
                gallery: 'galleryTitle' in cfg.i18n,
            };
        });

        expect(config.can).toEqual({ create: true, rename: true, delete: true, assign: true });
        expect(config.picker).toBe(true);
        expect(config.gallery).toBe(true);
    });

    test('the gallery inspector’s tree is still read-only', async ({ page }) => {
        await page.goto('/wp-admin/upload.php?mode=grid');
        await resetFolders(page);
        await createFolder(page, 'Inspector only');

        try {
            await page.goto('/wp-admin/post-new.php');
            await page.waitForFunction(() => Boolean((window as any).wp?.blocks?.getBlockType('folderfolio/gallery')));

            await page.evaluate(async () => {
                const wp = (window as any).wp;
                const block = wp.blocks.createBlock('folderfolio/gallery', {});
                await wp.data.dispatch('core/block-editor').insertBlocks(block);
                wp.data.dispatch('core/block-editor').selectBlock(block.clientId);
            });

            // A dispatched click: on a fresh user the editor's welcome guide
            // is open over everything, and dismissing it would be a
            // preference this spec then has to put back.
            const row = page.locator('.folderfolio-inspector .folderfolio-row', { hasText: 'Inspector only' });
            await row.locator('.folderfolio-row__name').dispatchEvent('click');

            await expect(page.locator('.folderfolio-inspector [aria-selected="true"]')).toContainText('Inspector only');
            await expect(page.locator('.folderfolio-inspector .folderfolio-row__menu')).toHaveCount(0);
            await expect(page.locator('.folderfolio-inspector [draggable="true"]')).toHaveCount(0);

            // Leave the editor clean, or the next navigation asks to confirm.
            await page.evaluate(() => (window as any).wp.data.dispatch('core/block-editor').resetBlocks([]));
        } finally {
            await page.goto('/wp-admin/upload.php?mode=grid');
            await resetFolders(page);
        }
    });
});
