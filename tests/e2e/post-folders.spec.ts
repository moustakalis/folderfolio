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

            // The title link is the drag handle; the row is not made draggable,
            // so its text still selects and another plugin's handle in it
            // still starts that plugin's drag.
            expect(await page.locator(`#post-${inside}`).getAttribute('draggable')).toBeNull();
            const dragging = await page.evaluate((id) => {
                const link = document.querySelector(`#post-${id} a.row-title`)!;
                link.dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer: new DataTransfer() }));
                const on = document.body.classList.contains('folderfolio-dragging');
                link.dispatchEvent(new DragEvent('dragend', { bubbles: true }));

                return on;
            }, inside);
            expect(dragging).toBe(true);

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

    // Review #24 (Nick, 25 Sep): inside a folder the status line counts the
    // folder, keeps it in its links, and says whose counts they are. Swapped
    // in place by the list refresh; untouched outside a folder.
    test('inside a folder the status line counts the folder and keeps it in its links', async ({ page }) => {
        await page.goto('/wp-admin/edit.php');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page, 'post');

        const launch = await createFolder(page, 'Launch', null, 'post');
        const published = [await makePost(page, 'Launch one'), await makePost(page, 'Launch two')];
        const draft = await page.evaluate(
            async () =>
                (await (window as any).wp.apiFetch({
                    path: '/wp/v2/posts',
                    method: 'POST',
                    data: { title: 'Launch draft', status: 'draft' },
                })).id as number
        );
        const loose = await makePost(page, 'Not in Launch');
        const line = () => page.locator('.subsubsub');

        try {
            await file(page, launch.id, [...published, draft]);
            await page.goto('/wp-admin/edit.php');
            await waitForTree(page, 'Launch');

            // Outside a folder: core's line, no lead-in.
            await expect(line().locator('.folderfolio-views-in')).toHaveCount(0);

            await page.locator('.folderfolio-tree .folderfolio-row', { hasText: 'Launch' }).first().click();
            await expect(line().locator('.folderfolio-views-in')).toHaveText('In Launch:');
            await expect(line().locator('li.all')).toContainText('All (3)');
            await expect(line().locator('li.publish')).toContainText('(2)');
            await expect(line().locator('li.draft')).toContainText('(1)');

            for (const href of await line().locator('a').evaluateAll((links) => links.map((a) => (a as HTMLAnchorElement).href))) {
                expect(new URL(href).searchParams.get('folderfolio_folder'), href).toBe(String(launch.id));
            }

            // Drafts stays in the folder: one row, the folder still selected.
            await line().locator('li.draft a').click();
            await expect.poll(() => rowIds(page)).toEqual([draft]);
            expect(new URL(page.url()).searchParams.get('folderfolio_folder')).toBe(String(launch.id));
            await expect(line().locator('li.draft a')).toHaveClass(/current/);

            // Back out of the folder in place: core's line again.
            await page.locator('.folderfolio-rail .folderfolio-row', { hasText: 'All Posts' }).first().click();
            await expect(line().locator('.folderfolio-views-in')).toHaveCount(0);
        } finally {
            await removePosts(page, [...published, draft, loose]);
            await resetFolders(page, 'post');
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
                // The panel state moved from core/edit-post to core/editor in
                // WordPress 6.5; 6.4 — the plugin's floor — has only the first.
                const store = typeof wp.data.select('core/editor').isEditorPanelOpened === 'function'
                    ? 'core/editor'
                    : 'core/edit-post';

                if (!wp.data.select(store).isEditorPanelOpened(name)) {
                    wp.data.dispatch(store).toggleEditorPanelOpened(name);
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

    test('beside the rail a narrow table takes core’s narrow shape, and a wide one keeps its columns', async ({ page }) => {
        // Measured 23 Sep: at a 1000px window the Posts table was 493px wide,
        // and Title and Folders were one letter wide. Core folds a table at a
        // 782px *window*; beside a 300px rail it has to fold by its own width.
        const measure = () =>
            page.evaluate(() => {
                const title = document.querySelector<HTMLElement>('#the-list tr[id^="post-"] .column-title');
                const author = document.querySelector<HTMLElement>('#the-list tr[id^="post-"] .column-author');

                return {
                    title: title?.getBoundingClientRect().width ?? 0,
                    authorShown: author ? getComputedStyle(author).display !== 'none' : false,
                    toggle: getComputedStyle(document.querySelector('#the-list .toggle-row')!).display,
                };
            });

        await page.setViewportSize({ width: 1000, height: 900 });
        await page.goto('/wp-admin/edit.php');
        await page.locator('#folderfolio-rail').waitFor();
        await expect(page.locator('#posts-filter')).toHaveClass(/folderfolio-list--narrow/);
        const narrow = await measure();
        expect(narrow.title).toBeGreaterThan(300);
        expect(narrow.authorShown).toBe(false);
        expect(narrow.toggle).toBe('block');

        await page.setViewportSize({ width: 1440, height: 900 });
        await expect(page.locator('#posts-filter')).not.toHaveClass(/folderfolio-list--narrow/);
        const wide = await measure();
        expect(wide.authorShown).toBe(true);
        expect(wide.toggle).toBe('none');
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
