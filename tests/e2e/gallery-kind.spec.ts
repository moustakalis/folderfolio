import { expect, test, type Page } from '@playwright/test';

import { createFolder, resetFolders, waitForTree } from './helpers/folders';

/**
 * A Gallery folder kind — tier 3 item 14, board KZsHhrffzKQYqUjTvdFszK (14a):
 * set from the ⋮ (Organise), images only wherever files arrive and a sentence
 * when they are refused, marked on the row, listed first by the gallery
 * block. The refusals themselves are asserted against the database in
 * GalleryKindTest; here is what a person sees.
 */

const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

/** Upload one file into a folder the way the library does — the parameter on the request. */
async function upload(page: Page, folderId: number, name: string, type: string): Promise<{ id?: number; error?: string }> {
    return page.evaluate(
        async ([folder, fileName, mime, png]) => {
            const body =
                mime === 'image/png'
                    ? Uint8Array.from(atob(png), (c) => c.charCodeAt(0))
                    : new TextEncoder().encode('Brief: nothing to see.\n');
            const form = new FormData();
            form.append('file', new File([body], fileName, { type: mime }));

            try {
                const media = await (window as any).wp.apiFetch({
                    path: `/wp/v2/media?folderfolio_folder=${folder}`,
                    method: 'POST',
                    body: form,
                });

                return { id: media.id as number };
            } catch (e) {
                return { error: String((e as { message?: string }).message ?? '') };
            }
        },
        [folderId, name, type, PNG] as const
    );
}

async function kindOf(page: Page, id: number): Promise<{ kind?: string; sort_files?: string | null }> {
    return page.evaluate(async (folderId) => {
        const tree = (await (window as any).wp.apiFetch({ path: '/folderfolio/v1/folders' })).data as any[];
        const walk = (nodes: any[]): any => {
            for (const node of nodes) {
                if (node.id === folderId) return node;
                const found = walk(node.children ?? []);
                if (found) return found;
            }
            return null;
        };
        const node = walk(tree);

        return { kind: node?.kind, sort_files: node?.sort_files };
    }, id);
}

async function removeMedia(page: Page, ids: number[]): Promise<void> {
    await page.evaluate(async (list) => {
        for (const id of list) {
            await (window as any).wp.apiFetch({ path: `/wp/v2/media/${id}?force=true`, method: 'DELETE' });
        }
    }, ids);
}

async function openMenu(page: Page, name: string) {
    await page.locator('.folderfolio-tree .folderfolio-row', { hasText: new RegExp(`^${name}`) }).first().click();
    await page.locator('.folderfolio-row__menu').click();
}

test.describe('a gallery folder', () => {
    test.setTimeout(120_000);

    test('is made from the ⋮, marked, and refuses what is not an image, saying why', async ({ page }) => {
        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);

        const hero = await createFolder(page, 'Hero shots');
        const campaign = await createFolder(page, 'Campaign');
        const made: number[] = [];

        try {
            const brief = await upload(page, campaign.id, 'folderfolio-brief.txt', 'text/plain');
            expect(brief.error).toBeUndefined();
            made.push(brief.id!);

            await page.reload();
            await waitForTree(page, 'Hero shots');

            await openMenu(page, 'Hero shots');
            const toggle = page.getByRole('menuitemcheckbox', { name: /^Gallery/ });
            await expect(toggle).toHaveAttribute('aria-checked', 'false');
            await expect(toggle).toContainText('images only');
            await toggle.click();

            // Marked on its row, in words as well as the glyph.
            const row = page.locator('.folderfolio-tree .folderfolio-row', { hasText: /^Hero shots/ }).first();
            await expect(row.locator('.folderfolio-row__gallery')).toHaveCount(1);
            await expect(row).toContainText('gallery');
            await expect(page.locator('.folderfolio-tree .folderfolio-row', { hasText: /^Campaign/ }).first().locator('.folderfolio-row__gallery')).toHaveCount(0);

            // It opens in the order arranged by hand.
            expect(await kindOf(page, hero.id)).toEqual({ kind: 'gallery', sort_files: 'custom' });

            await openMenu(page, 'Hero shots');
            await expect(page.getByRole('menuitemcheckbox', { name: /^Gallery/ })).toHaveAttribute('aria-checked', 'true');
            await page.keyboard.press('Escape');

            // An upload into it: an image goes in, anything else is refused
            // before it is stored, in the server's words.
            const dot = await upload(page, hero.id, 'folderfolio-dot.png', 'image/png');
            expect(dot.error).toBeUndefined();
            made.push(dot.id!);

            const refused = await upload(page, hero.id, 'folderfolio-notes.txt', 'text/plain');
            expect(refused.id).toBeUndefined();
            expect(refused.error).toContain('“Hero shots” is a gallery');

            // Filing into it from the library: refused with the same sentence.
            const filed = await page.evaluate(
                async ([folder, file]) => {
                    try {
                        await (window as any).wp.apiFetch({
                            path: '/folderfolio/v1/attachments/assign',
                            method: 'POST',
                            data: { folder_id: folder, attachment_ids: [file] },
                        });

                        return 'filed';
                    } catch (e) {
                        return String((e as any).error?.message ?? '');
                    }
                },
                [hero.id, brief.id!] as const
            );
            expect(filed).toContain('is a gallery, and a gallery holds images only');

            // A folder already holding other files is not made one, and the
            // notice sheet says how many and what to do.
            await openMenu(page, 'Campaign');
            await page.getByRole('menuitemcheckbox', { name: /^Gallery/ }).click();
            const notice = page.locator('.folderfolio-toast--notice');
            await expect(notice).toContainText('holds 1 file that is not an image');
            await expect(page.locator('.folderfolio-tree .folderfolio-row', { hasText: /^Campaign/ }).first().locator('.folderfolio-row__gallery')).toHaveCount(0);
            await notice.getByRole('button', { name: 'Dismiss' }).click();

            // And back to a folder: the mark goes.
            await openMenu(page, 'Hero shots');
            await page.getByRole('menuitemcheckbox', { name: /^Gallery/ }).click();
            await expect(row.locator('.folderfolio-row__gallery')).toHaveCount(0);
        } finally {
            await removeMedia(page, made);
            await resetFolders(page);
        }
    });

    test('the gallery block lists galleries first, and choosing one chooses it in the tree', async ({ page }) => {
        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);

        const brand = await createFolder(page, 'Brand');
        const hero = await createFolder(page, 'Hero shots', brand.id);
        await createFolder(page, 'Plain one');
        await page.evaluate(
            (id) => (window as any).wp.apiFetch({ path: `/folderfolio/v1/folders/${id}/kind`, method: 'POST', data: { kind: 'gallery' } }),
            hero.id
        );

        try {
            await page.goto('/wp-admin/post-new.php');
            await page.waitForFunction(() => Boolean((window as any).wp?.blocks?.getBlockType('folderfolio/gallery')));

            await page.evaluate(async () => {
                const wp = (window as any).wp;
                const block = wp.blocks.createBlock('folderfolio/gallery', {});
                await wp.data.dispatch('core/block-editor').insertBlocks(block);
                wp.data.dispatch('core/block-editor').selectBlock(block.clientId);
            });

            // CSS, not getByRole: on a fresh user the editor's sidebar sits
            // behind its welcome guide, and a role query skips what is hidden
            // (block-editor.spec's reason for the same).
            const galleries = page.locator('.folderfolio-inspector__galleries[role="group"][aria-label="Galleries"]');
            await expect(galleries).toBeAttached();
            await expect(galleries.locator('.folderfolio-inspector__gallery')).toHaveCount(1);
            await expect(galleries).toContainText('Hero shots · Brand');
            await expect(galleries).not.toContainText('Plain one');

            // Above the tree, in the same box.
            const above = await page.evaluate(() => {
                const group = document.querySelector('.folderfolio-inspector__galleries');
                const tree = document.querySelector('.folderfolio-inspector .folderfolio-tree');

                return Boolean(group && tree && group.compareDocumentPosition(tree) & Node.DOCUMENT_POSITION_FOLLOWING);
            });
            expect(above).toBe(true);

            // Dispatched, for block-editor.spec's reason: the welcome guide.
            await galleries.locator('button[aria-label="Hero shots in Brand"]').dispatchEvent('click');
            await expect(page.locator('.folderfolio-inspector .folderfolio-tree [aria-selected="true"]')).toContainText('Hero shots');
            await expect.poll(() =>
                page.evaluate(() => {
                    const wp = (window as any).wp;
                    const block = wp.data.select('core/block-editor').getBlocks()[0];

                    return block?.attributes.folderIds ?? [];
                })
            ).toEqual([hero.id]);

            await page.evaluate(() => (window as any).wp.data.dispatch('core/block-editor').resetBlocks([]));
        } finally {
            await page.goto('/wp-admin/upload.php?mode=grid');
            await resetFolders(page);
        }
    });
});
