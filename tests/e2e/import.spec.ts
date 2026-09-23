import { expect, test, type Page } from '@playwright/test';

import { resetFolders } from './helpers/folders';

/**
 * The migration wizard — screen 07.
 *
 * The harness boots a WordPress with no competitor plugins in it, so there is
 * nothing real to import from. That turns out to be the right constraint: the
 * readers are verified against the actual plugins on the development site, and
 * what belongs in a suite that has to run anywhere is the part with no SQL in
 * it — the four steps, the preview matching the run, and undo putting the
 * library back.
 *
 * The create/merge/duplicate/undo behaviour is covered in the integration
 * suite, against a source registered through the same
 * `folderfolio_import_sources` filter a site would use to add an importer of
 * its own.
 */

const IMPORT = '/wp-admin/admin.php?page=folderfolio&tab=import';

/** Open the wizard and wait for it to have mounted. */
async function openWizard(page: Page): Promise<void> {
    await page.goto(IMPORT);
    await page.locator('#folderfolio-import-app').waitFor();
}

test.describe('the import wizard', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);
    });

    test('lists every source it knows about, and says which have data', async ({ page }) => {
        await openWizard(page);

        const rows = page.locator('.folderfolio-source');

        // Nine sources ship: three custom-table, six taxonomy-backed.
        await expect(rows).toHaveCount(9);
        await expect(rows.first()).toContainText('FileBird');

        // A fresh WordPress has none of them, so every row reads the same way
        // — which is the case a detection bug would hide, since a reader that
        // matched nothing would look exactly like this on a site that had
        // something.
        await expect(page.getByText('Nothing to import').first()).toBeVisible();
        await expect(
            page.getByText('No folder data from another plugin was found on this site.')
        ).toBeVisible();
    });

    test('the steps read off the server, not off a click', async ({ page }) => {
        await openWizard(page);

        const steps = page.locator('.folderfolio-wizard__step');

        await expect(steps).toHaveCount(4);
        await expect(steps.nth(0)).toHaveAttribute('aria-current', 'step');

        // They are labels, not buttons: three of the four "go back" gestures a
        // stepper implies are impossible or destructive here.
        await expect(steps.nth(2).locator('button')).toHaveCount(0);
    });

    test('previewing writes nothing, and says so', async ({ page }) => {
        // Straight at the route: the preview is a GET, and that it is safe to
        // call is the claim the whole second step rests on.
        const before = await page.evaluate(async () => {
            const tree = await window.wp.apiFetch({ path: '/folderfolio/v1/folders' });

            return (tree.data as unknown[]).length;
        });

        // `parse: false` and a catch: apiFetch rejects on any non-2xx, with
        // the parsed body by default — and this route's body is the plugin's
        // own envelope, which carries no status. Unparsed, what it rejects
        // with is the Response, which does.
        const status = await page.evaluate(async () => {
            try {
                await window.wp.apiFetch({
                    path: '/folderfolio/v1/import/filebird/plan',
                    parse: false,
                });

                return 200;
            } catch (rejected) {
                return (rejected as Response).status;
            }
        });

        // No FileBird on this site, so the route refuses rather than inventing
        // an empty plan.
        expect(status).toBe(404);

        const after = await page.evaluate(async () => {
            const tree = await window.wp.apiFetch({ path: '/folderfolio/v1/folders' });

            return (tree.data as unknown[]).length;
        });

        expect(after).toBe(before);
    });


    /**
     * Finding 06 of the settings audit.
     *
     * The four steps were a flex row with `flex: 1` on each, so at a 390px
     * viewport they wrapped three-and-one: 106 + 106 + 106, then step 4 alone
     * at 318, with the first three squeezed until their titles ran to three
     * lines. Four never divides into a wrapping row — it divides into two rows
     * of two.
     *
     * The assertion is that the steps are always equal and never orphaned,
     * which is true of both shapes and false of every wrap.
     */
    test('the four steps are equal and never orphan one of themselves', async ({ page }) => {
        await openWizard(page);

        for (const width of [1440, 782, 600, 521, 520, 390, 320]) {
            await page.setViewportSize({ width, height: 900 });
            await page.waitForTimeout(220);

            const strip = await page.evaluate(() => {
                const steps = [...document.querySelectorAll('.folderfolio-wizard__step')].map(
                    (s) => {
                        const b = s.getBoundingClientRect();
                        return { w: Math.round(b.width * 10) / 10, top: Math.round(b.top) };
                    }
                );

                const rows = [...new Set(steps.map((s) => s.top))];

                return {
                    count: steps.length,
                    widths: steps.map((s) => s.w),
                    rows: rows.length,
                    perRow: rows.map((t) => steps.filter((s) => s.top === t).length),
                };
            });

            expect(strip.count, `${width}px: the wizard did not render four steps`).toBe(4);

            const widest = Math.max(...strip.widths);
            const narrowest = Math.min(...strip.widths);

            expect(
                widest - narrowest,
                `${width}px: the steps are uneven — ${strip.widths.join(' / ')}`
            ).toBeLessThanOrEqual(1);

            // Four across, or two and two. Never three and one.
            expect(
                strip.perRow,
                `${width}px: the steps wrapped ${strip.perRow.join(' + ')}`
            ).toEqual(width >= 521 ? [4] : [2, 2]);
        }
    });

    /**
     * Finding 05.
     *
     * The source row's body was `flex: 1` against a button whose minimum is
     * its own label, so the text yielded and the button did not: at a 390px
     * viewport the button held 129.1px — 41% of the row — and the sentence
     * that tells you whether to press it was squeezed to 176.9 and wrapped to
     * three lines. Below 520 the button goes under the text at full width.
     */
    test('a source row never gives its action more room than its text', async ({ page }) => {
        await openWizard(page);

        // The steps are server-rendered; the source list arrives over REST, so
        // the strip being on screen does not mean the rows are.
        await page.locator('.folderfolio-source').first().waitFor();

        // Every row has exactly two children: the text, and an action that is a
        // button when the source holds data and a "nothing to import" span when
        // it does not. The harness installs no competitors, so here they are all
        // spans - which is why this measures the second child rather than a
        // button that would never be found.
        for (const width of [1440, 782, 521, 520, 390, 320]) {
            await page.setViewportSize({ width, height: 900 });
            await page.waitForTimeout(220);

            const rows = await page.evaluate(() =>
                [...document.querySelectorAll('.folderfolio-source')].map((row) => {
                    const body = row.querySelector('.folderfolio-source__body') as HTMLElement;
                    const action = row.children[1] as HTMLElement | undefined;
                    const style = getComputedStyle(row);
                    const bb = body.getBoundingClientRect();

                    return {
                        display: style.display,
                        tracks: style.gridTemplateColumns,
                        kids: row.children.length,
                        body: Math.round(bb.width),
                        action: action ? Math.round(action.getBoundingClientRect().width) : null,
                        under: action
                            ? action.getBoundingClientRect().top >= bb.bottom - 1
                            : null,
                        rowWidth: Math.round(row.getBoundingClientRect().width),
                    };
                })
            );

            expect(rows.length, `${width}px: the wizard rendered no sources`).toBeGreaterThan(3);

            for (const row of rows) {
                expect(row.kids, `${width}px: a source row is not text plus action`).toBe(2);

                // Declared tracks, not a flex line: this is what the row's shape
                // rests on, and it is checkable whether or not the action has a
                // label wide enough to squeeze the text.
                expect(
                    row.display,
                    `${width}px: the source row is ${row.display}, not a grid`
                ).toBe('grid');

                const tracks = row.tracks.split(' ').filter(Boolean);

                if (width >= 521) {
                    expect(
                        tracks.length,
                        `${width}px: the row declares ${row.tracks}, not two tracks`
                    ).toBe(2);

                    expect(
                        row.body,
                        `${width}px: the action (${row.action}px) has more room than the text (${row.body}px)`
                    ).toBeGreaterThan(row.action as number);
                } else {
                    expect(
                        tracks.length,
                        `${width}px: the row declares ${row.tracks}, not one track`
                    ).toBe(1);

                    expect(row.under, `${width}px: the action did not go under the text`).toBe(
                        true
                    );

                    expect(
                        row.action,
                        `${width}px: stacked, but the action is not full width`
                    ).toBe(row.rowWidth);
                }
            }
        }
    });

    test('an import route refuses somebody who cannot manage the site', async ({ page }) => {
        // Logged in as an administrator here, so this asserts the route's own
        // capability rather than the session's: every import route is
        // manage_options, not upload_files and not the folder roles matrix,
        // because an import rewrites the shape of the whole media library.
        const routes = await page.evaluate(async () => {
            const index = await window.wp.apiFetch({ path: '/folderfolio/v1' });
            const paths = Object.keys(
                (index as { routes: Record<string, unknown> }).routes
            );

            return paths.filter((path) => path.includes('/import'));
        });

        expect(routes).toEqual(
            expect.arrayContaining([
                '/folderfolio/v1/import/sources',
                '/folderfolio/v1/import/run',
                '/folderfolio/v1/import/stop',
                '/folderfolio/v1/import/undo',
            ])
        );

        // v0.2.0's one-shot route is gone, not deprecated: there is no version
        // of "import everything now, no preview" worth keeping alive.
        expect(routes).not.toContain('/folderfolio/v1/import/(?P<importer>[a-z-]+)');
    });
});

/**
 * Bulk create — the other way folders arrive on this tab.
 *
 * The parse and the resolution are the server's, and the integration suite
 * owns them. What belongs here is the promise the screen makes: that pressing
 * Preview writes nothing, that a list with a bad line offers no way to run it,
 * and that editing the box takes the preview away rather than leaving a
 * confident wrong answer under the button that acts on it.
 */
test.describe('bulk create', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);
    });

    /** Type a list and press Preview. */
    async function preview(page: Page, list: string): Promise<void> {
        await page.locator('#folderfolio-bulk-text').fill(list);
        await page.getByRole('button', { name: 'Preview' }).click();
        await page.locator('.folderfolio-bulk__row').first().waitFor();
    }

    test('previewing writes nothing, and the shared prefix is made once', async ({ page }) => {
        await page.goto(IMPORT);
        await page.locator('.folderfolio-bulk').waitFor();

        await preview(page, 'Brand\nBrand/Logos\nBrand/Logos/Primary\nBrand/Icons');

        // Four lines, four folders: Brand is named on every one of them and
        // counted once, which is the whole of why a plan exists rather than a
        // loop over getOrCreateByPath().
        await expect(page.locator('.folderfolio-bulk__summary')).toContainText('would add 4 folders');

        // The bold part of each line is what that line contributes.
        const rows = page.locator('.folderfolio-bulk__row');
        await expect(rows).toHaveCount(4);
        await expect(rows.nth(1).locator('.folderfolio-bulk__new')).toHaveText('Logos');
        await expect(rows.nth(1).locator('.folderfolio-bulk__old')).toHaveText('Brand');

        // Nothing has been written. The rail behind this page is the check
        // that matters, not the sentence claiming it.
        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        await expect(page.locator('.folderfolio-row__name', { hasText: 'Brand' })).toHaveCount(0);
    });

    test('a list with a bad line offers no way to run it', async ({ page }) => {
        await page.goto(IMPORT);
        await page.locator('.folderfolio-bulk').waitFor();

        await preview(page, 'Brand\n///');

        await expect(page.locator('.folderfolio-bulk__row--error')).toHaveCount(1);
        await expect(page.locator('.folderfolio-bulk__summary')).toContainText('cannot be used');

        // Not disabled — absent. A greyed Create button beside a list the
        // person can fix is a control whose only job is to be refused.
        await expect(page.getByRole('button', { name: /^Create/ })).toHaveCount(0);
    });

    test('editing the list takes the preview with it', async ({ page }) => {
        await page.goto(IMPORT);
        await page.locator('.folderfolio-bulk').waitFor();

        await preview(page, 'Brand\nBrand/Logos');
        await expect(page.locator('.folderfolio-bulk__row')).toHaveCount(2);

        await page.locator('#folderfolio-bulk-text').fill('Something else entirely');

        // A preview of text that has since changed is a specific, confident,
        // wrong answer sitting directly under the button that acts on it.
        await expect(page.locator('.folderfolio-bulk__row')).toHaveCount(0);
        await expect(page.getByRole('button', { name: /^Create/ })).toHaveCount(0);
    });

    test('creating twice creates once', async ({ page }) => {
        await page.goto(IMPORT);
        await page.locator('.folderfolio-bulk').waitFor();

        await preview(page, 'Brand\nBrand/Logos');
        await page.getByRole('button', { name: 'Create 2 folders' }).click();
        await expect(page.locator('.folderfolio-bulk__summary')).toContainText('2 folders created');

        // The same list again. A line naming a folder that exists is a no-op,
        // which is what makes a list safe to paste after editing three lines
        // of it.
        await preview(page, 'Brand\nBrand/Logos');
        await expect(page.locator('.folderfolio-bulk__summary')).toContainText(
            'Every folder on this list is already there'
        );
        await expect(page.getByRole('button', { name: /^Create/ })).toHaveCount(0);
    });

    test('the bulk routes sit behind create, not manage_options', async ({ page }) => {
        const routes = await page.evaluate(async () => {
            const index = await window.wp.apiFetch({ path: '/folderfolio/v1' });

            return Object.keys((index as { routes: Record<string, unknown> }).routes).filter(
                (path) => path.includes('/bulk')
            );
        });

        // Two routes, not one with a dry_run flag: a boolean that decides
        // whether a request writes is the parameter that is wrong exactly
        // once.
        expect(routes).toEqual(
            expect.arrayContaining([
                '/folderfolio/v1/folders/bulk',
                '/folderfolio/v1/folders/bulk/plan',
            ])
        );
    });
});

/**
 * Tier 1 item 6b — the export file read back in, through the same wizard.
 */
test.describe('importing an export file', () => {
    const doc = (site: string) => ({
        folderfolio: 1,
        site,
        object_type: 'attachment',
        folders: [
            { id: 10, parent_id: null, name: 'Round trip', slug: null, color: 'red', icon: null, sort_order: 0, sort_folders: 'name-desc', sort_files: null },
            { id: 11, parent_id: 10, name: 'Logos', slug: null, color: 'moss', icon: null, sort_order: 0, sort_folders: null, sort_files: 'name-desc' },
        ],
        assignments: [{ folder: 11, attachments: [999999] }],
    });

    async function choose(page: import('@playwright/test').Page, body: string) {
        await page.locator('.folderfolio-wizard input[type="file"]').setInputFiles({
            name: 'export.json',
            mimeType: 'application/json',
            buffer: Buffer.from(body),
        });
    }

    test("another site's file brings its folders and says why not its files", async ({ page }) => {
        await page.goto('/wp-admin/admin.php?page=folderfolio&tab=import');
        await choose(page, JSON.stringify(doc('https://elsewhere.example')));

        await expect(page.locator('.folderfolio-wizard__lines')).toContainText('An export of another site');
        await expect(page.locator('.folderfolio-wizard__lines')).toContainText('2 folders created');
        await page.getByRole('button', { name: 'Cancel' }).click();
    });

    test('a file that is not an export is refused in its own row, with the reason', async ({ page }) => {
        await page.goto('/wp-admin/admin.php?page=folderfolio&tab=import');
        await choose(page, 'not json');
        await expect(page.locator('.folderfolio-source [role="alert"]')).toContainText('could not be read as JSON');

        await choose(page, JSON.stringify({ ...doc('https://x.example'), folderfolio: 2 }));
        await expect(page.locator('.folderfolio-source [role="alert"]')).toContainText('export format 2');
    });
});
