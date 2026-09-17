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
