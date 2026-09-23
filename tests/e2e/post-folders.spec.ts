import { expect, test, type Page } from '@playwright/test';

import { createFolder, resetFolders, waitForTree } from './helpers/folders';

/**
 * Folders for posts, pages and custom post types — tier 3 item 12, board
 * KZsHhrffzKQYqUjTvdFszK. The same rail, ⋮ menu and Folders column on a post
 * type's list screen, one tree per type, a Folders panel in the editor, and
 * Add New from inside a folder filing the new post there. The rules that keep
 * the trees apart are the server's and are asserted in PostFoldersTest.
 */

async function makePost(page: Page, title: string, type: 'posts' | 'pages' = 'posts'): Promise<number> {
    return page.evaluate(
        async ([postTitle, route]) =>
            (await (window as any).wp.apiFetch({
                path: `/wp/v2/${route}`,
                method: 'POST',
                data: { title: postTitle, status: 'publish' },
            })).id as number,
        [title, type] as const
    );
}

async function removePosts(page: Page, ids: number[], type: 'posts' | 'pages' = 'posts'): Promise<void> {
    await page.evaluate(
        async ([list, route]) => {
            for (const id of list) {
                await (window as any).wp.apiFetch({ path: `/wp/v2/${route}/${id}?force=true`, method: 'DELETE' }).catch(() => undefined);
            }
        },
        [ids, type] as const
    );
}

async function file(page: Page, folderId: number, ids: number[]): Promise<void> {
    await page.evaluate(
        async ([folder, list]) => {
            await (window as any).wp.apiFetch({
                path: '/folderfolio/v1/assignments',
                method: 'POST',
                data: { folder_id: folder, attachment_ids: list },
            });
        },
        [folderId, ids] as const
    );
}

async function rowIds(page: Page): Promise<number[]> {
    return page.evaluate(() =>
        [...document.querySelectorAll('#the-list > tr[id^="post-"]')].map((row) => Number(row.id.slice(5)))
    );
}

test.describe('folders for posts', () => {
    test.setTimeout(120_000);

    test('the Posts screen has its own tree, and a folder filters the list in place', async ({ page }) => {
        await page.goto('/wp-admin/edit.php');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page, 'post');
        await resetFolders(page);

        const news = await createFolder(page, 'News desk', null, 'post');
        const media = await createFolder(page, 'Only for media');
        const inside = await makePost(page, 'Filed post');
        const outside = await makePost(page, 'Loose post');

        try {
            await file(page, news.id, [inside]);
            await page.goto('/wp-admin/edit.php');
            await waitForTree(page, 'News desk');

            // One tree per type: the media folder is not here.
            await expect(page.locator('.folderfolio-tree .folderfolio-row', { hasText: 'Only for media' })).toHaveCount(0);
            await expect(page.locator('#folderfolio-rail-app')).toHaveAttribute('aria-label', 'Posts folders');
            await expect(page.locator('.folderfolio-rail')).toContainText('All Posts');

            // The Folders column names the folder, linking to this screen.
            const cell = page.locator(`#post-${inside} .column-folderfolio_folders a`);
            await expect(cell).toHaveText('News desk');
            await expect(cell).toHaveAttribute('href', /edit\.php\?post_type=post&folderfolio_folder=\d+/);

            // Selecting filters without a reload.
            await page.evaluate(() => ((window as any).__ffSamePage = true));
            await page.locator('.folderfolio-tree .folderfolio-row', { hasText: 'News desk' }).first().click();
            await expect.poll(() => rowIds(page)).toEqual([inside]);
            expect(await page.evaluate(() => (window as any).__ffSamePage)).toBe(true);
            expect(new URL(page.url()).searchParams.get('folderfolio_folder')).toBe(String(news.id));

            // Unassigned is the posts in no folder.
            await page.locator('.folderfolio-rail .folderfolio-row', { hasText: 'Unassigned' }).first().click();
            await expect.poll(async () => (await rowIds(page)).includes(inside)).toBe(false);
            expect(await rowIds(page)).toContain(outside);

            // And the media library's tree does not show the post folder.
            await page.goto('/wp-admin/upload.php?mode=grid');
            await waitForTree(page, 'Only for media');
            await expect(page.locator('.folderfolio-tree .folderfolio-row', { hasText: 'News desk' })).toHaveCount(0);
        } finally {
            await removePosts(page, [inside, outside]);
            await resetFolders(page, 'post');
            await resetFolders(page);
            void media;
        }
    });

    test('Quick Edit still opens after a folder filters the list', async ({ page }) => {
        await page.goto('/wp-admin/edit.php');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page, 'post');

        const folder = await createFolder(page, 'Quick', null, 'post');
        const post = await makePost(page, 'Quick edit me');

        try {
            await file(page, folder.id, [post]);
            await page.goto('/wp-admin/edit.php');
            await waitForTree(page, 'Quick');
            await page.locator('.folderfolio-tree .folderfolio-row', { hasText: 'Quick' }).first().click();
            await expect.poll(() => rowIds(page)).toEqual([post]);

            // The row swapped in; the handler on #the-list survived it.
            await page.locator(`#post-${post}`).hover();
            await page.locator(`#post-${post} button.editinline`).click();
            await expect(page.locator(`#edit-${post}`)).toBeVisible();
            await page.locator(`#edit-${post} button.cancel`).click();
        } finally {
            await removePosts(page, [post]);
            await resetFolders(page, 'post');
        }
    });

    test('the ⋮ menu keeps what a post folder can do, and nothing only files can', async ({ page }) => {
        await page.goto('/wp-admin/edit.php');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page, 'post');
        await createFolder(page, 'Menu check', null, 'post');

        try {
            await page.goto('/wp-admin/edit.php');
            await waitForTree(page, 'Menu check');
            await page.locator('.folderfolio-tree .folderfolio-row', { hasText: 'Menu check' }).first().click();
            await page.locator('.folderfolio-row__menu').click();

            const menu = page.locator('.folderfolio-menu');
            await expect(menu).toContainText('Rename');
            await expect(menu).toContainText('Copy');
            await expect(menu).toContainText('Subfolders');
            await expect(menu).not.toContainText('Download as ZIP');
            await expect(menu).not.toContainText('Copy with files');
            await expect(menu.getByRole('menuitem', { name: /^Files/ })).toHaveCount(0);
        } finally {
            await resetFolders(page, 'post');
        }
    });

    test('Add New from inside a folder files the new post there, and the editor says so', async ({ page }) => {
        await page.goto('/wp-admin/edit.php?post_type=page');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page, 'page');
        const folder = await createFolder(page, 'Landing pages', null, 'page');
        await createFolder(page, 'Legal', null, 'page');
        let created = 0;

        try {
            await page.goto('/wp-admin/edit.php?post_type=page');
            await waitForTree(page, 'Landing pages');
            await page.locator('.folderfolio-tree .folderfolio-row', { hasText: 'Landing pages' }).first().click();

            const addNew = page.locator('#wpbody-content .wrap a.page-title-action').first();
            await expect(addNew).toHaveAttribute('href', new RegExp(`folderfolio_folder=${folder.id}`));

            await page.goto(await addNew.getAttribute('href') ?? '');
            await page.waitForFunction(() => Boolean((window as any).wp?.data?.select('core/editor')?.getCurrentPostId()));
            created = await page.evaluate(() => (window as any).wp.data.select('core/editor').getCurrentPostId() as number);

            const filed = await page.evaluate(
                async (id) =>
                    ((await (window as any).wp.apiFetch({ path: `/folderfolio/v1/attachments/${id}/folders` })).data.folders as Array<{ id: number }>).map((f) => f.id),
                created
            );
            expect(filed).toEqual([folder.id]);

            // The panel: open the document sidebar and the panel, read the boxes.
            await page.evaluate(() => {
                const wp = (window as any).wp;
                wp.data.dispatch('core/edit-post')?.openGeneralSidebar?.('edit-post/document');
                const name = 'folderfolio-post-folders/folderfolio-post-folders';

                if (!wp.data.select('core/editor').isEditorPanelOpened(name)) {
                    wp.data.dispatch('core/editor').toggleEditorPanelOpened(name);
                }
            });

            const panel = page.locator('.folderfolio-post-folders');
            await panel.waitFor();
            await expect(panel.getByLabel('Landing pages')).toBeChecked();
            await expect(panel.getByLabel('Legal')).not.toBeChecked();
            await expect(panel).toContainText('In 1 folder.');

            // Ticking a second folder adds — many-to-many.
            await panel.getByLabel('Legal').dispatchEvent('click');
            await expect(panel).toContainText('In 2 folders.');
            await expect
                .poll(async () =>
                    page.evaluate(
                        async (id) => ((await (window as any).wp.apiFetch({ path: `/folderfolio/v1/attachments/${id}/folders` })).data.folders as unknown[]).length,
                        created
                    )
                )
                .toBe(2);
        } finally {
            await page.goto('/wp-admin/edit.php?post_type=page');
            await page.locator('#folderfolio-rail').waitFor();

            if (created) {
                await removePosts(page, [created], 'pages');
            }

            await resetFolders(page, 'page');
        }
    });

    test('settings list the post types, media always on', async ({ page }) => {
        await page.goto('/wp-admin/admin.php?page=folderfolio&tab=settings');
        const group = page.getByRole('group', { name: 'Folders for' });

        await expect(group.getByLabel('Media')).toBeChecked();
        await expect(group.getByLabel('Media')).toBeDisabled();
        await expect(group.getByLabel('Posts')).toBeChecked();
        await expect(group.getByLabel('Pages')).toBeChecked();
    });
});
