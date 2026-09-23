import { expect, test, type Page } from '@playwright/test';

import { createFolder, resetFolders, waitForTree } from './helpers/folders';

/**
 * Upload a folder structure from disk — tier 2 item 9.
 *
 * ## How a directory is dropped without a desktop
 *
 * Playwright cannot drag a directory from the operating system. What it can do
 * is fire a `drop` on the grid's drop zone carrying a `DataTransfer` whose
 * `items` hand out `FileSystemEntry`-shaped objects — which is all
 * `moxie.js`'s `FileDrop` reads: `webkitGetAsEntry()`, `createReader()`,
 * `readEntries()`, `file()`. So core's own directory walk runs, core's own
 * uploader sends the files, and the server files them. The only thing faked
 * is the desktop.
 */

/** A 2×2 PNG, as upload.spec.ts uses. */
const PNG =
    'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAF0lEQVQIHWNkYGD4z8DAwMgAAkAWGAAAIAUBAWtL1p0AAAAASUVORK5CYII=';

/**
 * Drop a tree on the grid: each path is a file — "Brand/Logos/a.png" — and a
 * path without a slash is a loose file dropped beside the directory.
 */
async function dropTree(page: Page, paths: string[]): Promise<void> {
    await page.evaluate(
        ([list, png]) => {
            const bytes = Uint8Array.from(atob(png), (c) => c.charCodeAt(0));
            type Entry = Record<string, unknown>;
            const dirs = new Map<string, Entry[]>();
            const roots: Entry[] = [];
            const loose: File[] = [];

            const dir = (path: string): Entry[] => {
                let kids = dirs.get(path);

                if (!kids) {
                    kids = [];
                    dirs.set(path, kids);

                    const name = path.split('/').pop() as string;
                    const entry: Entry = {
                        isFile: false,
                        isDirectory: true,
                        name,
                        fullPath: '/' + path,
                        createReader() {
                            let done = false;

                            return {
                                readEntries(cb: (e: Entry[]) => void) {
                                    cb(done ? [] : ((done = true), kids as Entry[]));
                                },
                            };
                        },
                    };
                    const parent = path.includes('/') ? path.slice(0, path.lastIndexOf('/')) : null;

                    (parent === null ? roots : dir(parent)).push(entry);
                }

                return kids;
            };

            for (const path of list) {
                const name = path.split('/').pop() as string;
                const file = new File([bytes], name, { type: name.endsWith('.png') ? 'image/png' : '' });

                if (!path.includes('/')) {
                    loose.push(file);
                    continue;
                }

                dir(path.slice(0, path.lastIndexOf('/'))).push({
                    isFile: true,
                    isDirectory: false,
                    name,
                    fullPath: '/' + path,
                    file(cb: (f: File) => void) {
                        cb(file);
                    },
                });
            }

            const items = [
                ...roots.map((entry) => ({ kind: 'file', webkitGetAsEntry: () => entry, getAsFile: () => null })),
                ...loose.map((file) => ({
                    kind: 'file',
                    webkitGetAsEntry: () => ({ isFile: true, isDirectory: false, name: file.name, fullPath: '/' + file.name, file: (cb: (f: File) => void) => cb(file) }),
                    getAsFile: () => file,
                })),
            ];
            const transfer = new DataTransfer();
            transfer.items.add(new File([bytes], 'types.png', { type: 'image/png' }));
            Object.defineProperty(transfer, 'items', { value: items });

            document.body.dispatchEvent(new DragEvent('drop', { dataTransfer: transfer, bubbles: true, cancelable: true }));
        },
        [paths, PNG] as const
    );
}

/** Folder path → the file names filed directly in it, under one root. */
async function filed(page: Page, rootId: number): Promise<Record<string, string[]>> {
    return page.evaluate(async (root) => {
        const tree = await window.wp.apiFetch({ path: '/folderfolio/v1/folders' });
        const out: Record<string, string[]> = {};
        const walk = async (nodes: any[], prefix: string, inside: boolean): Promise<void> => {
            for (const node of nodes) {
                const here = node.id === root || inside;
                const path = node.id === root ? '.' : prefix === '.' ? node.name : `${prefix}/${node.name}`;

                if (here) {
                    const files = await window.wp.apiFetch({ path: `/folderfolio/v1/folders/${node.id}/attachments` });
                    const names: string[] = [];

                    for (const id of files.data.attachment_ids) {
                        const media = await window.wp.apiFetch({ path: `/wp/v2/media/${id}?_fields=title` });
                        names.push(media.title.rendered);
                    }

                    out[path] = names.sort();
                }

                await walk(node.children ?? [], here ? path : prefix, here);
            }
        };

        await walk(tree.data, '', false);

        return out;
    }, rootId);
}

async function deleteUploads(page: Page): Promise<void> {
    await page.evaluate(async () => {
        const media = await window.wp.apiFetch({ path: '/wp/v2/media?search=ff9e2e&per_page=100&_fields=id' });

        for (const item of media) {
            await window.wp.apiFetch({ path: `/wp/v2/media/${item.id}?force=true`, method: 'DELETE' });
        }
    });
}

test.describe('uploading a folder structure', () => {
    test.setTimeout(180_000);

    let root = 0;

    test.beforeEach(async ({ page }) => {
        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);
        root = (await createFolder(page, 'Dropped here')).id;
        await page.goto(`/wp-admin/upload.php?mode=grid&folderfolio_folder=${root}`);
        await waitForTree(page, 'Dropped here');
    });

    test.afterEach(async ({ page }) => {
        await deleteUploads(page);
        await resetFolders(page);
    });

    test('makes the folders under the selected one and files each file in its own', async ({ page }) => {
        await dropTree(page, [
            'ff9e2e-loose.png',
            'Brand/ff9e2e-brand.png',
            'Brand/Logos/ff9e2e-primary.png',
            'Brand/Icons/Line/ff9e2e-arrow.png',
            'Brand/.DS_Store',
            'Brand/__MACOSX/._ff9e2e-primary.png',
        ]);

        await expect
            .poll(() => filed(page, root), { timeout: 120_000 })
            .toEqual({
                '.': ['ff9e2e-loose'],
                Brand: ['ff9e2e-brand'],
                'Brand/Icons': [],
                'Brand/Icons/Line': ['ff9e2e-arrow'],
                'Brand/Logos': ['ff9e2e-primary'],
            });

        // Litter was left behind without a word: core lists a refused file
        // under the grid, and `.DS_Store` is refused by extension.
        await expect(page.locator('.upload-errors .upload-error')).toHaveCount(0);

        // The rail learnt about the folders without a reload.
        await page.locator('.folderfolio-tree .folderfolio-row', { hasText: 'Dropped here' }).locator('.folderfolio-row__switcher').click();
        await waitForTree(page, 'Brand');
    });

    // Found verifying this feature, older than it: a query naming a folder is
    // not one core's Query watches the upload queue for, so an upload made
    // in a folder never appeared in the grid until the next query.
    test('shows each upload in the grid it was dropped on, with its title', async ({ page }) => {
        await dropTree(page, ['ff9e2e-tile.png', 'Trip/ff9e2e-trip.png']);

        await expect(page.locator('.attachments-browser li.attachment').first()).toBeVisible({ timeout: 60_000 });
        await expect
            .poll(
                () =>
                    page.$$eval('.attachments-browser li.attachment', (tiles) =>
                        tiles.map((tile) => tile.getAttribute('aria-label')).sort()
                    ),
                { timeout: 120_000 }
            )
            .toEqual(['ff9e2e-tile', 'ff9e2e-trip']);
    });

    test('a structure too deep makes no folders, uploads the files here, and says why', async ({ page }) => {
        const deep = Array.from({ length: 21 }, (_, i) => `D${i + 1}`).join('/');

        await dropTree(page, [`Deep/ff9e2e-top.png`, `Deep/${deep}/ff9e2e-bottom.png`]);

        await expect(page.locator('.folderfolio-toast--notice')).toContainText('could not be made', { timeout: 60_000 });
        await expect
            .poll(() => filed(page, root), { timeout: 120_000 })
            .toEqual({ '.': ['ff9e2e-bottom', 'ff9e2e-top'] });
    });

    // Board UzMC1qdGkxa2JQckXu65tW, option B: a link under core's Select
    // Files. The system's folder chooser cannot be driven, so the chooser's
    // answer is put on the input — Files carrying webkitRelativePath, as a
    // chooser returns them — and its `change` is what runs.
    test('“or select a folder” sits under Select Files and uploads a structure', async ({ page }) => {
        await page.locator('.page-title-action').click();

        const link = page.locator('.uploader-inline .upload-ui .browser + .folderfolio-select-folder button');
        await expect(link).toHaveText('or select a folder');

        await page.evaluate((png) => {
            const bytes = Uint8Array.from(atob(png), (c) => c.charCodeAt(0));
            const input = document.querySelector('.uploader-inline .folderfolio-select-folder input') as HTMLInputElement;
            const file = (name: string, rel: string) =>
                Object.defineProperty(new File([bytes], name, { type: 'image/png' }), 'webkitRelativePath', { value: rel });

            Object.defineProperty(input, 'files', {
                configurable: true,
                value: [file('ff9e2e-chosen.png', 'Chosen/ff9e2e-chosen.png'), file('ff9e2e-deeper.png', 'Chosen/Deeper/ff9e2e-deeper.png')],
            });
            input.dispatchEvent(new Event('change'));
        }, PNG);

        await expect
            .poll(() => filed(page, root), { timeout: 120_000 })
            .toEqual({ '.': [], Chosen: ['ff9e2e-chosen'], 'Chosen/Deeper': ['ff9e2e-deeper'] });
    });
});
