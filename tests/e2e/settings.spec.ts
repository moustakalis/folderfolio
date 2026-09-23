import { expect, test, type Page } from '@playwright/test';

import { createFolder, resetFolders, waitForTree } from './helpers/folders';

/**
 * The settings screen — screen 08.
 *
 * Three tabs and a form, which a unit test covers well enough on its own. What
 * it cannot cover is the half that makes the screen worth having: that saving
 * a value here changes what the media library does on the next page load. Both
 * of the tests that matter below cross that line.
 */

const SETTINGS = '/wp-admin/admin.php?page=folderfolio';

/**
 * Put the form back the way it shipped.
 *
 * There is no REST route for settings and no reason to add one for a form
 * posted twice in a site's lifetime, so the reset goes through the form — which
 * has the side benefit of exercising the save path in every spec that uses it.
 */
/**
 * Pick one of the two count modes.
 *
 * By its label, not its input. The segmented control's radios are visually
 * hidden — the label is what a user sees and clicks, and the input sits
 * underneath one of them, so clicking the input directly is both unlike the
 * user and ambiguous about which segment was hit.
 */
async function chooseCount(page: Page, label: string): Promise<void> {
    await page.locator('.folderfolio-seg label', { hasText: label }).click();
}

async function resetSettings(page: Page): Promise<void> {
    await page.goto(`${SETTINGS}&tab=settings`);

    await chooseCount(page, 'Inherited');
    await page.getByLabel('Default sort').selectOption('name-asc');
    await page.getByLabel('Undo window').fill('5');
    await page.getByRole('checkbox', { name: 'Create — Author' }).check();
    await page.getByLabel('Opens in').selectOption('');

    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByText('Settings saved.')).toBeVisible();

    // And the per-user startup folder (7b), which the form does not reach. A
    // spec that fails between pressing Start here and pressing it again
    // leaves it set, and every later spec that expects the site's folder —
    // or none — then fails for a reason that is not its own.
    await page.goto('/wp-admin/upload.php?folderfolio_folder=');
    await page.locator('#folderfolio-rail').waitFor();
    await page.evaluate(() =>
        window.wp.apiFetch({
            path: '/folderfolio/v1/preferences',
            method: 'POST',
            data: { rail: { startup: null } },
        })
    );
}

test.describe('the settings screen', () => {
    test.afterEach(async ({ page }) => {
        await resetSettings(page);
    });

    /**
     * The screen's ground is the panel, and it is actually painted.
     *
     * The box came off on 21 Sep and the canvas went white with it, so that
     * every token keeps the ground it was measured against. The rule that does
     * it sits on `#wpcontent` — which is an *ancestor* of `.folderfolio`, and
     * the first version read `background: var(--ff-panel)` there, where that
     * token does not exist. It resolved to nothing, computed to
     * `transparent`, and the screen silently shipped the other design: box
     * gone, ground still grey.
     *
     * No unit test can see that. `_tokens.css` was correct and `_settings.css`
     * was correct; only the pairing of the two was wrong, and only a browser
     * knows which selector can see which custom property. Hence this.
     *
     * Asserted against the component's own `--ff-panel` rather than a literal,
     * so it follows the token instead of restating it.
     */
    test('the page itself is painted with the panel token, not left transparent', async ({
        page,
    }) => {
        await page.goto(`${SETTINGS}&tab=settings`);
        await page.locator('.folderfolio-settings').waitFor();

        const ground = await page.evaluate(() => {
            const paint = (selector: string): string => {
                const el = document.querySelector(selector);

                return el === null ? 'MISSING' : getComputedStyle(el).backgroundColor;
            };

            const component = document.querySelector('.folderfolio') as HTMLElement;

            return {
                token: getComputedStyle(component).getPropertyValue('--ff-panel').trim(),
                wpcontent: paint('#wpcontent'),
                wpbody: paint('#wpbody'),
                wpbodyContent: paint('#wpbody-content'),
                // The one the rail lives on is deliberately untouched, and
                // this spec's own page is the only place the rule applies.
                card: paint('.folderfolio-settings'),
            };
        });

        expect(ground.token, 'the panel token is not resolving at all').toMatch(/^#|^rgb/);

        // #fff and rgb(255, 255, 255) are the same colour spelled two ways.
        const panelIsWhite = /^#fff{1,2}$|^#ffffff$/i.test(ground.token);

        for (const [name, painted] of [
            ['#wpcontent', ground.wpcontent],
            ['#wpbody', ground.wpbody],
            ['#wpbody-content', ground.wpbodyContent],
        ] as const) {
            expect(
                painted,
                `${name} is ${painted} — the ground rule resolved to nothing, so the box came `
                    + 'off but the canvas stayed grey'
            ).not.toBe('rgba(0, 0, 0, 0)');

            if (panelIsWhite) {
                expect(painted, `${name} is not the panel colour`).toBe('rgb(255, 255, 255)');
            }
        }

        // And the card is no longer a card: no ground of its own to be a box.
        expect(
            ground.card,
            'the settings card is painting a background again — it is the page now'
        ).toBe('rgba(0, 0, 0, 0)');
    });

    test('is one page with three tabs, landing on Settings', async ({ page }) => {
        await page.goto(SETTINGS);

        const tabs = page.locator('.folderfolio-settings__tabs a');

        await expect(tabs).toHaveText(['Settings', 'Import', 'Status']);
        await expect(tabs.nth(0)).toHaveAttribute('aria-current', 'page');

        // The tabs are links, not buttons: each one is a URL, so a bookmark
        // and a middle-click both work.
        await expect(tabs.nth(2)).toHaveAttribute('href', /tab=status/);
    });

    test('the administrator row is pinned, and stays ticked across a save', async ({ page }) => {
        await page.goto(SETTINGS);

        const create = page.getByRole('checkbox', { name: 'Create — Administrator' });

        await expect(create).toBeChecked();
        await expect(create).toBeDisabled();

        // Disabled inputs post nothing. Without the hidden field beside each
        // one, the first save would drop the administrator row from the
        // matrix — which is the one row nobody can restore from the admin.
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(page.getByText('Settings saved.')).toBeVisible();

        await expect(page.getByRole('checkbox', { name: 'Delete — Administrator' })).toBeChecked();
    });

    /**
     * The help line under Folder counts leads with the option that is in
     * force.
     *
     * It described *Inherited* only until 21 Sep, so on a site set to Direct
     * only — the default — it argued for the choice you had just declined and
     * never described the one running. Describing only the active option
     * would fail the other way, so it names both and puts the active one
     * first, server-side.
     *
     * Asserted against the checked radio rather than a fixed string, so it
     * holds whichever way the setting happens to be stored.
     */
    const helpLeadsWithTheActiveOption = async (page: Page): Promise<void> => {
        const checked = (
            await page.locator('.folderfolio-seg input:checked + label').textContent()
        )?.trim();

        expect(checked, 'no option is checked, so there is nothing to lead with').toBeTruthy();

        const lead = (
            await page.locator('.folderfolio-field__help strong').first().textContent()
        )?.trim();

        expect(
            lead,
            `the help line leads with "${lead}" while "${checked}" is the option in force`
        ).toBe(checked);
    };

    test('the count setting changes what the library counts', async ({ page }) => {
        // Eight page loads against a WordPress running on php-wasm: seeding a
        // folder tree and a file, reading the badge, changing the setting,
        // reading it again, and clearing up after itself. The default 30s is
        // enough on its own and not enough behind twenty other tests.
        test.setTimeout(120_000);

        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);

        const parent = await createFolder(page, 'Brand');
        const child = await createFolder(page, 'Logos', parent.id);

        // One file, filed in the child only. Inherited says the parent holds
        // it; direct says it does not. That disagreement is the whole setting.
        //
        // The file is created here rather than assumed: the harness boots a
        // fresh WordPress, and a fresh WordPress has an empty media library.
        const attachmentId = await page.evaluate(async (folderId) => {
            // A 1x1 transparent PNG — the smallest thing the media library
            // will accept and generate no thumbnails worth waiting for.
            const bytes = Uint8Array.from(
                atob(
                    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
                ),
                (c) => c.charCodeAt(0)
            );

            const form = new FormData();
            form.append('file', new File([bytes], 'folderfolio-count.png', { type: 'image/png' }));

            const media = await window.wp.apiFetch({
                path: '/wp/v2/media',
                method: 'POST',
                body: form,
            });

            await window.wp.apiFetch({
                path: '/folderfolio/v1/attachments/assign',
                method: 'POST',
                data: { folder_id: folderId, attachment_ids: [media.id] },
            });

            return media.id as number;
        }, child.id);

        const parentBadge = async (): Promise<string> =>
            (
                (await page
                    .locator('.folderfolio-tree .folderfolio-row', { hasText: 'Brand' })
                    .first()
                    .locator('.folderfolio-row__count')
                    .textContent()) ?? ''
            ).trim();

        await page.reload();
        await waitForTree(page, 'Brand');
        expect(await parentBadge()).toBe('1');

        await page.goto(`${SETTINGS}&tab=settings`);
        await helpLeadsWithTheActiveOption(page);

        await chooseCount(page, 'Direct only');
        await expect(page.getByRole('radio', { name: 'Direct only' })).toBeChecked();
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(page.getByText('Settings saved.')).toBeVisible();

        // The saved page is rendered from the new value, so the clauses have
        // swapped. Both orders are covered by the two calls.
        await helpLeadsWithTheActiveOption(page);

        await page.goto('/wp-admin/upload.php?mode=grid');
        await waitForTree(page, 'Brand');
        expect(await parentBadge()).toBe('0');

        // Clean up from a page that has finished loading. Running the two
        // cleanup evaluates straight after the assertion raced the grid's own
        // startup once in a full-suite run, and an evaluate whose execution
        // context is destroyed mid-flight fails the test for a reason that has
        // nothing to do with the setting.
        await page.goto('/wp-admin/upload.php');
        await page.locator('#folderfolio-rail').waitFor();

        await page.evaluate(
            async (id) =>
                window.wp.apiFetch({ path: `/wp/v2/media/${id}?force=true`, method: 'DELETE' }),
            attachmentId
        );
        await resetFolders(page);
    });

    test('the default sort setting is the sort the rail opens on', async ({ page }) => {
        await page.goto(`${SETTINGS}&tab=settings`);
        await page.getByLabel('Default sort').selectOption('name-desc');
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(page.getByText('Settings saved.')).toBeVisible();

        await page.goto('/wp-admin/upload.php?mode=grid');

        // The rail, not the tree: the sort menu is in the toolbar and does not
        // need a folder to exist, and the spec before this one leaves none.
        await page.locator('#folderfolio-rail').waitFor();

        await page.getByRole('button', { name: 'Sort' }).click();

        await expect(
            page.getByRole('menuitemradio', { name: 'Name, Z to A' })
        ).toHaveAttribute('aria-checked', 'true');
    });


    /**
     * The startup folder — tier 1 item 7.
     *
     * Four assertions, and the last two are the ones that matter. Anybody can
     * make a media library open in a folder; the reason this feature is in
     * 1.0 rather than left to the market is that **it says so and it lets you
     * out**. FileBird forces its remembered folder onto `upload.php` with no
     * user action and nothing on screen: 28 of 47 files vanish and the only
     * clue is that the grid looks short.
     *
     * The exit is the fragile half, and it is fragile in a way that would
     * pass a casual look: clearing the filter works, and then the next page
     * load puts you straight back. That is why the × is asserted **and then
     * the URL it produced is loaded again**.
     */
    test('the startup folder opens the library in a folder, says so, and lets you out', async ({
        page,
    }) => {
        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);

        const folder = await createFolder(page, 'Opens Here');

        await page.goto(`${SETTINGS}&tab=settings`);
        await page.getByLabel('Opens in').selectOption(String(folder.id));
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(page.getByText('Settings saved.')).toBeVisible();

        // 1. A bare arrival is sent to the folder, and the URL says so rather
        //    than the filter happening invisibly inside a query.
        await page.goto('/wp-admin/upload.php');
        await expect(page).toHaveURL(new RegExp(`folderfolio_folder=${folder.id}\\b`));

        // 2. The screen says it, which is the whole difference between this
        //    and what the market ships.
        const note = page.locator('.folderfolio-startup-note');
        await expect(note).toBeVisible();
        await expect(page.locator('.folderfolio-crumbs__current')).toHaveText('Opens Here');

        // 3. The way out works, and takes the sentence with it — the sentence
        //    outliving the filter was a real bug, found in the browser and not
        //    by any suite.
        // The library's labelled Clear filter; `__clear` is the picker's icon.
        await page.locator('.folderfolio-crumbs__control', { hasText: /clear filter/i }).click();
        await expect(note).toHaveCount(0);

        // 4. And the way out **stays** out on the next load. The URL the ×
        //    produces carries the folder key with an empty value, which is
        //    what stops the redirect firing again; if it deleted the key
        //    instead, this navigation would land back in the folder.
        const cleared = page.url();
        expect(cleared).toContain('folderfolio_folder=');

        await page.goto(cleared);
        // Not `toHaveURL(cleared)`: core's wp-admin-canonical replaceState
        // re-spells `?folderfolio_folder=` as `?folderfolio_folder`. PHP reads
        // both as present-and-empty, which is all StartupFolder asks
        // (array_key_exists), so what matters is that the key is still there
        // and no folder id came back.
        await expect(page).toHaveURL(/[?&]folderfolio_folder(=)?(&|$)/);
        await expect(page).not.toHaveURL(/folderfolio_folder=\d/);
        await expect(page.locator('.folderfolio-startup-note')).toHaveCount(0);

        // …and the re-spelt URL holds too, since it is the one a reload uses.
        await page.reload();
        await expect(page).not.toHaveURL(/folderfolio_folder=\d/);
        await expect(page.locator('.folderfolio-startup-note')).toHaveCount(0);
    });

    /**
     * The per-user half — tier 1 item 7b.
     *
     * Three things, and the third is the one that would be easy to ship
     * broken: the toggle writes, the write survives a page load, and turning
     * it off falls back to the **site's** folder rather than to nothing.
     *
     * It also asserts the rail's width comes through untouched, because the
     * toggle sends `startup` alone and relies on the controller merging it
     * onto what is stored. A version of this that sent the whole preference
     * object would pass every other assertion here and quietly reset the
     * width of anyone who had dragged the rail.
     */
    test('a person can set their own startup folder, and theirs wins', async ({ page }) => {
        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);

        const mine = await createFolder(page, 'Mine');
        const theirs = await createFolder(page, 'Site Wide');

        // The site sends everyone to one folder.
        await page.goto(`${SETTINGS}&tab=settings`);
        await page.getByLabel('Opens in').selectOption(String(theirs.id));
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(page.getByText('Settings saved.')).toBeVisible();

        // This person picks another, from the row that says where they are.
        await page.goto(`/wp-admin/upload.php?folderfolio_folder=${mine.id}`);
        const toggle = page.getByRole('button', { name: 'Start here' });
        await expect(toggle).toHaveAttribute('aria-pressed', 'false');
        await toggle.click();
        await expect(toggle).toHaveAttribute('aria-pressed', 'true');
        // The press is optimistic; the button is disabled until the write
        // lands. Navigating before then abandons the request.
        await expect(toggle).toBeEnabled();

        // 1. It survives the round trip, and 2. it beats the site's.
        await page.goto('/wp-admin/upload.php');
        await expect(page).toHaveURL(new RegExp(`folderfolio_folder=${mine.id}\\b`));
        await expect(page.getByRole('button', { name: 'Start here' })).toHaveAttribute(
            'aria-pressed',
            'true'
        );

        // The rail's width came back untouched — the write was a merge, not a
        // replacement.
        const width = await page.evaluate(async () => {
            const r = await window.wp.apiFetch({ path: '/folderfolio/v1/preferences' });

            return r.data.rail.width;
        });
        expect(width).toBeGreaterThan(0);

        // 3. Off falls back to the site's folder, not to no folder at all.
        await page.getByRole('button', { name: 'Start here' }).click();
        await expect(page.getByRole('button', { name: 'Start here' })).toHaveAttribute(
            'aria-pressed',
            'false'
        );
        await expect(page.getByRole('button', { name: 'Start here' })).toBeEnabled();

        await page.goto('/wp-admin/upload.php');
        await expect(page).toHaveURL(new RegExp(`folderfolio_folder=${theirs.id}\\b`));

        await page.goto(`${SETTINGS}&tab=settings`);
        await page.getByLabel('Opens in').selectOption('');
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(page.getByText('Settings saved.')).toBeVisible();
    });

    /**
     * The site sending you somewhere is not you choosing it.
     *
     * The toggle reflects this person's own value and nothing else. If it
     * showed the *effective* folder, pressing it while the site sent you here
     * would clear a preference you never set, the site's folder would keep
     * arriving, and the button would look broken while working correctly.
     */
    test('a site-wide startup folder does not press this person\'s toggle', async ({ page }) => {
        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);

        const folder = await createFolder(page, 'Everyone Here');

        await page.goto(`${SETTINGS}&tab=settings`);
        await page.getByLabel('Opens in').selectOption(String(folder.id));
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(page.getByText('Settings saved.')).toBeVisible();

        await page.goto('/wp-admin/upload.php');
        await expect(page).toHaveURL(new RegExp(`folderfolio_folder=${folder.id}\\b`));

        // Sent here, told why, and not pretending it was my idea.
        await expect(page.locator('.folderfolio-startup-note')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Start here' })).toHaveAttribute(
            'aria-pressed',
            'false'
        );

        await page.goto(`${SETTINGS}&tab=settings`);
        await page.getByLabel('Opens in').selectOption('');
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(page.getByText('Settings saved.')).toBeVisible();
    });

    /**
     * Unassigned is a destination, not the absence of one.
     *
     * `0` is the one value in this setting that a careless read turns into
     * "off", and it is a real choice — "show me what is not filed yet" is the
     * arrival somebody whose job is filing media actually wants. Settings has
     * a unit test for the same three-way; this asserts it survives the form,
     * the option, the redirect and the rail.
     */
    test('Unassigned can be the startup folder, and zero does not read as off', async ({ page }) => {
        await page.goto(`${SETTINGS}&tab=settings`);
        await page.getByLabel('Opens in').selectOption('0');
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(page.getByText('Settings saved.')).toBeVisible();

        await page.goto('/wp-admin/upload.php');

        await expect(page).toHaveURL(/folderfolio_folder=0\b/);
        await expect(page.locator('.folderfolio-crumbs__current')).toHaveText('Unassigned');

        // Put it back, so the specs after this one open on an unfiltered
        // library like every other spec in this file expects.
        await page.goto(`${SETTINGS}&tab=settings`);
        await page.getByLabel('Opens in').selectOption('');
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(page.getByText('Settings saved.')).toBeVisible();
    });

    /**
     * Finding 02 of the settings audit.
     *
     * The roles matrix is the only table on either of the plugin's screens,
     * and a table has an intrinsic minimum it will not go below. It floored at
     * 361.8px against a 318px content box at a 390px viewport, and
     * `.folderfolio-matrix-wrap` is `overflow-x: visible`, so there was no
     * scroller to absorb it and the *page* scrolled sideways instead: 7px at
     * 390, 37px at 360, 77px at 320.
     *
     * Two assertions, because either alone passes on a broken screen.
     * `scrollWidth === clientWidth` alone is satisfied by an overflow smaller
     * than the padding around it — which is exactly how this was first
     * mis-measured as fixed, with the table still 4.2px past the card's own
     * border at 320. And a table-inside-card check alone says nothing about
     * anything else on the page.
     *
     * The precondition matters too: an empty table fits everything. The column
     * count is asserted so this cannot pass on a matrix that failed to render.
     */
    test('the roles matrix fits its card at every width, and the page never scrolls sideways', async ({
        page,
    }) => {
        await page.goto(`${SETTINGS}&tab=settings`);
        await page.locator('.folderfolio-matrix').waitFor();

        type Row = {
            width: number;
            columns: number;
            roles: number;
            table: number;
            content: number;
            overContent: number;
            pastBorder: number;
            pageOverflow: number;
            headFont: string;
        };

        const rows: Row[] = [];

        // 783/782 is where wp-admin drops the admin menu; 521/520 is our own
        // breakpoint; the rest are real phones. 1280 is the control: nothing
        // below 520 should reach it.
        for (const width of [1280, 960, 783, 782, 521, 520, 480, 390, 375, 360, 320]) {
            await page.setViewportSize({ width, height: 900 });
            // Let the media query and the table's own layout settle.
            await page.waitForTimeout(250);

            rows.push(
                await page.evaluate((w) => {
                    const matrix = document.querySelector('.folderfolio-matrix') as HTMLElement;
                    const card = document.querySelector('.folderfolio-settings') as HTMLElement;
                    const body = document.querySelector(
                        '.folderfolio-settings__body'
                    ) as HTMLElement;
                    const de = document.documentElement;
                    const cs = getComputedStyle(body);

                    // clientWidth INCLUDES padding, so it is the wrong box to
                    // compare a child against.
                    const content =
                        body.clientWidth
                        - parseFloat(cs.paddingLeft)
                        - parseFloat(cs.paddingRight);

                    const mb = matrix.getBoundingClientRect();
                    const cb = card.getBoundingClientRect();

                    return {
                        width: w,
                        columns: matrix.querySelectorAll('thead th').length,
                        roles: matrix.querySelectorAll('tbody tr').length,
                        table: Math.round(mb.width * 10) / 10,
                        content: Math.round(content * 10) / 10,
                        overContent: Math.round((mb.width - content) * 10) / 10,
                        pastBorder: Math.round((mb.right - cb.right) * 10) / 10,
                        pageOverflow: de.scrollWidth - de.clientWidth,
                        headFont: getComputedStyle(
                            matrix.querySelector('thead th') as HTMLElement
                        ).fontSize,
                        headCase: getComputedStyle(
                            matrix.querySelector('thead th:nth-child(2)') as HTMLElement
                        ).textTransform,
                    };
                }, width)
            );
        }

        console.table(rows);

        // The precondition: five roles against five abilities (Lock since
        // tier 2 item 10), plus the role column. An empty table would satisfy
        // everything below.
        for (const row of rows) {
            expect(row.columns, `${row.width}px: the matrix did not render its heads`).toBe(6);
            expect(row.roles, `${row.width}px: the matrix did not render its roles`).toBe(5);
        }

        // The defect, at every width.
        for (const row of rows) {
            expect(
                row.pageOverflow,
                `${row.width}px: the page scrolls sideways by ${row.pageOverflow}px`
            ).toBe(0);

            expect(
                row.overContent,
                `${row.width}px: the table is ${row.table}px in a ${row.content}px content box`
            ).toBeLessThanOrEqual(0.5);

            expect(
                row.pastBorder,
                `${row.width}px: the table reaches past the card's own border`
            ).toBeLessThan(0);
        }

        // The breakpoint fires where the stylesheet says, and nowhere else.
        const at = (w: number) => rows.find((r) => r.width === w)!;

        expect(at(1280).headFont, 'the wide metrics are untouched').toBe('11px');
        expect(at(521).headFont, '521 is above the breakpoint').toBe('11px');
        expect(at(520).headFont, '520 is where the narrow metrics start').toBe('10px');
        expect(at(390).headFont).toBe('10px');
        // …and half a pixel less at 360 and below, for the fifth column.
        expect(at(360).headFont).toBe('9.5px');
        expect(at(320).headFont).toBe('9.5px');

        // Sentence case on a phone — what makes room for the fifth column.
        expect(at(521).headCase).toBe('uppercase');
        expect(at(520).headCase).toBe('none');
    });


    /**
     * Finding A1 of the 21 Sep re-audit — the half of finding 02 that the
     * guard above could not see.
     *
     * The guard above sweeps eleven widths with the roles this site happens to
     * have, and every one of their names contains a space. A table's intrinsic
     * minimum is set by the longest *unbreakable* word in it, so that sweep
     * measured a floor for those five strings and not a floor for the column.
     *
     * A role's display name comes from whatever registered it. Measured
     * 21 Sep with one German compound — a shape any of the membership plugins
     * can produce, and so can a plugin that registers a slug as a label — the
     * table went from its 272px floor to 458.4px and the page scrolled
     * sideways 91px at 390, 121 at 360 and 161 at 320. Worse than the overflow
     * finding 02 was opened for.
     *
     * Two assertions, and the second is the one that matters. It is easy to
     * stop the overflow by letting the column be crushed instead:
     * `overflow-wrap: anywhere` on its own drops the role column's minimum to
     * one character, the four ability columns take their declared 108px, and
     * the result is a 27px role column in a 199px-tall row — at ordinary
     * widths, with ordinary names. So this asserts that the column keeps a
     * readable width *as well as* that the page does not scroll.
     */
    test('the roles matrix survives a role name that cannot be broken', async ({ page }) => {
        await page.goto(`${SETTINGS}&tab=settings`);
        await page.locator('.folderfolio-matrix').waitFor();

        // Long, and with no space, hyphen or soft break anywhere in it.
        const HOSTILE = 'Veranstaltungsmedienverwaltungsbeauftragter';

        const planted = await page.evaluate((name) => {
            const cell = document.querySelector(
                '.folderfolio-matrix tbody tr:nth-child(3) th'
            ) as HTMLElement | null;

            if (cell === null) {
                return false;
            }

            cell.textContent = name;

            return /\s/.test(name) === false;
        }, HOSTILE);

        // The precondition: the string really is unbreakable and the cell
        // really took it. A test that plants nothing asserts nothing.
        expect(planted, 'the hostile role name was not planted').toBe(true);

        const rows: Array<{
            width: number;
            table: number;
            content: number;
            overContent: number;
            pageOverflow: number;
            roleColumn: number;
            roleFont: number;
        }> = [];

        for (const width of [1280, 960, 782, 650, 600, 521, 520, 390, 375, 360, 320]) {
            await page.setViewportSize({ width, height: 900 });
            await page.waitForTimeout(250);

            rows.push(
                await page.evaluate((w) => {
                    const matrix = document.querySelector('.folderfolio-matrix') as HTMLElement;
                    const body = document.querySelector(
                        '.folderfolio-settings__body'
                    ) as HTMLElement;
                    const de = document.documentElement;
                    const cs = getComputedStyle(body);

                    const content =
                        body.clientWidth
                        - parseFloat(cs.paddingLeft)
                        - parseFloat(cs.paddingRight);

                    const mb = matrix.getBoundingClientRect();

                    // Row 1 is Administrator — an ordinary name. This is the
                    // column that must not be starved by the fix for row 3.
                    const ordinary = matrix.querySelector(
                        'tbody tr:nth-child(1) th'
                    ) as HTMLElement;

                    return {
                        width: w,
                        table: Math.round(mb.width * 10) / 10,
                        content: Math.round(content * 10) / 10,
                        overContent: Math.round((mb.width - content) * 10) / 10,
                        pageOverflow: de.scrollWidth - de.clientWidth,
                        roleColumn: Math.round(ordinary.getBoundingClientRect().width * 10) / 10,
                        roleFont: parseFloat(getComputedStyle(ordinary).fontSize),
                    };
                }, width)
            );
        }

        console.table(rows);

        for (const row of rows) {
            expect(
                row.pageOverflow,
                `${row.width}px: one unbreakable role name scrolls the page sideways by `
                    + `${row.pageOverflow}px`
            ).toBe(0);

            expect(
                row.overContent,
                `${row.width}px: the table is ${row.table}px in a ${row.content}px content box`
            ).toBeLessThanOrEqual(0.5);

            // Six characters of the font's own size is about the narrowest a
            // role column can be and still be read. Expressed against the
            // font because the metrics come down at 520 with everything else.
            expect(
                row.roleColumn,
                `${row.width}px: the role column collapsed to ${row.roleColumn}px — the fix for `
                    + 'the long name is starving the ordinary ones'
            ).toBeGreaterThan(row.roleFont * 6);
        }
    });


    /**
     * Finding 04 of the settings audit.
     *
     * `.folderfolio-status th` declares `width: 220px`, which is right while
     * there is room for it and wrong once there is not: at a 318px table it
     * left the value 90px — 28% of the row for the answer and 72% for the
     * label — and wrapped "Deepest path" to four lines. Below 520 the key and
     * the value stack instead, so both get the full width.
     *
     * The assertion is the *relationship*, not the 220: a key column that is
     * wider than its value column is the defect, whatever the numbers are on
     * the machine running this.
     */
    test('the status table gives the answer more room than the label, or stacks', async ({
        page,
    }) => {
        await page.goto(`${SETTINGS}&tab=status`);
        await page.locator('.folderfolio-status').waitFor();

        const rows: Array<{
            width: number;
            pairs: number;
            stacked: boolean;
            key: number;
            value: number;
            tallest: number;
        }> = [];

        for (const width of [1440, 782, 521, 520, 390, 320]) {
            await page.setViewportSize({ width, height: 900 });
            await page.waitForTimeout(220);

            rows.push(
                await page.evaluate((w) => {
                    const trs = [...document.querySelectorAll('.folderfolio-status tr')];
                    const first = trs[0];
                    const th = first.querySelector('th') as HTMLElement;
                    const td = first.querySelector('td') as HTMLElement;
                    const tb = th.getBoundingClientRect();
                    const db = td.getBoundingClientRect();

                    return {
                        width: w,
                        pairs: trs.filter((tr) => tr.querySelector('th') && tr.querySelector('td'))
                            .length,
                        stacked: tb.bottom <= db.top + 1,
                        key: Math.round(tb.width),
                        value: Math.round(db.width),
                        tallest: Math.round(
                            Math.max(...trs.map((tr) => tr.getBoundingClientRect().height))
                        ),
                    };
                }, width)
            );
        }

        console.table(rows);

        for (const row of rows) {
            // The precondition: a table of key/value pairs. One with no rows
            // satisfies everything below.
            expect(row.pairs, `${row.width}px: the status table rendered no pairs`).toBeGreaterThan(
                3
            );

            if (row.stacked) {
                // Stacked, both halves get the row.
                expect(row.key, `${row.width}px: stacked, but the key is not full width`).toBe(
                    row.value
                );
            } else {
                expect(
                    row.value,
                    `${row.width}px: the label (${row.key}px) has more room than the answer (${row.value}px)`
                ).toBeGreaterThan(row.key);
            }
        }

        const at = (w: number) => rows.find((r) => r.width === w)!;

        expect(at(521).stacked, '521 is above the breakpoint').toBe(false);
        expect(at(520).stacked, '520 is where the table stacks').toBe(true);
    });

    /**
     * Finding 07.
     *
     * Two repair actions and one that is not. As a wrapping flex row, Copy
     * report fell onto a line of its own below 480 and landed directly under
     * the first repair button, which read as a third repair tool. It is pushed to the
     * other end of the row now, so the grouping is declared rather than
     * whatever the wrap happens to produce — and below 520 all three take a
     * row each, which groups nothing wrongly.
     */
    test('the copy control is not grouped with the repair tools', async ({ page }) => {
        await page.goto(`${SETTINGS}&tab=status`);
        await page.locator('.folderfolio-tools').waitFor();

        for (const width of [1440, 782, 600, 521, 520, 390]) {
            await page.setViewportSize({ width, height: 900 });
            await page.waitForTimeout(220);

            const shape = await page.evaluate(() => {
                const tools = document.querySelector('.folderfolio-tools') as HTMLElement;
                const body = document.querySelector(
                    '.folderfolio-settings__body'
                ) as HTMLElement;
                const cs = getComputedStyle(body);
                const contentRight =
                    body.getBoundingClientRect().right - parseFloat(cs.paddingRight);

                const kids = [...tools.children] as HTMLElement[];
                const copy = tools.querySelector('[data-folderfolio-copy]') as HTMLElement;

                return {
                    children: kids.length,
                    rows: new Set(kids.map((k) => Math.round(k.getBoundingClientRect().top))).size,
                    copyIsLast: kids.indexOf(copy) === kids.length - 1,
                    copyFlushRight:
                        Math.abs(copy.getBoundingClientRect().right - contentRight) < 1.5,
                    copyFullWidth:
                        Math.abs(
                            copy.getBoundingClientRect().width -
                                (contentRight - body.getBoundingClientRect().left -
                                    parseFloat(cs.paddingLeft))
                        ) < 1.5,
                };
            });

            expect(shape.children, `${width}px: the tools row lost a control`).toBe(3);
            expect(shape.copyIsLast, `${width}px: the copy control moved`).toBe(true);

            if (width >= 521) {
                expect(shape.rows, `${width}px: the tools row wrapped`).toBe(1);
                expect(
                    shape.copyFlushRight,
                    `${width}px: the copy control is not at the end of the row`
                ).toBe(true);
            } else {
                expect(shape.rows, `${width}px: the tools are not one per row`).toBe(3);
                expect(
                    shape.copyFullWidth,
                    `${width}px: stacked, but the copy control is not full width`
                ).toBe(true);
            }
        }
    });

    test('Status reports the schema and offers the report as text', async ({ page }) => {
        await page.goto(`${SETTINGS}&tab=status`);

        await expect(page.locator('.folderfolio-status')).toContainText('Database');
        await expect(page.locator('.folderfolio-status')).toContainText(
            'Every folder agrees with its parent'
        );

        // The report is in a textarea rather than behind the copy button
        // alone, so it can still be selected by hand with scripts off.
        await expect(page.locator('#folderfolio-report')).toHaveValue(/FolderFolio \d/);

        await expect(page.getByRole('button', { name: 'Repair folder tree' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Forget deleted files' })).toBeVisible();
    });
});
