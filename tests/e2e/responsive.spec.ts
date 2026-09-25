import { expect, test, type Page } from '@playwright/test';

import { createFolder, resetFolders, waitForTree } from './helpers/folders';

/**
 * The rail across viewport widths.
 *
 * Written after an audit found the worst layout problems below 900px and
 * almost nothing above it — and found them only because a resize misfired and
 * left the browser at 640px by accident. This makes that accident repeatable.
 *
 * **It asserts mechanics, not taste.** The design board has thirteen screens
 * and not one of them is narrow. What is checked is the set of things that
 * are wrong at any width under any design: a page that scrolls sideways, a
 * label clipped by its own button, a child rendering outside its parent, a
 * breakpoint that does not fire where its own CSS says it does.
 *
 * The one narrow decision that has since been made — below 782px the rail is
 * a closed bar that opens on a tap, argued and measured in the disclosure
 * block at the foot of _rail.css — is asserted as a shape and a default, not
 * as a pixel count. Everything else undesigned is still measured and printed
 * rather than asserted, so the numbers are in the run output.
 *
 * **Every width is also photographed**, into `test-results/responsive/`. An
 * assertion can only fail on what it was told to look for, and every serious
 * finding in this plugin's design audit was something no assertion had been
 * told to look for — a rail whose collapsed state drew across the page while
 * measuring 28px wide and passing every property check. The screenshots are
 * there to be looked at after a green run, not only after a red one.
 *
 * One page load, then the viewport changes: media queries and flex respond
 * live, and a reload per width would cost seven wp-admin boots on php-wasm.
 *
 * No test carries `test.fail()` any more. Both that did — the 240px clipping
 * and the collapsed rail — came off the moment their fixes landed, because
 * Playwright reports a `test.fail()` that passes as a failure and so an
 * annotation cannot survive its fix by accident. That is what the annotation
 * is for; add one rather than deleting a test that describes a real defect.
 */

/** 783 and 782 bracket the one breakpoint the rail declares. */
const WIDTHS = [1600, 1280, 960, 783, 782, 600, 390];

interface Shot {
    width: number;
    docOverflowX: number;
    clippedTools: string[];
    spillingChildren: string[];
    stacked: boolean;
    sideBySide: boolean;
    railBeforeTitlePx: number;
    toolWidths: number[];
    searchWidth: number;

    /** The rail's height in the state the page loads in at this width. */
    closedHeight: number;

    /** Whether the reopen bar is on screen — i.e. whether this is a phone. */
    disclosure: boolean;
}

type Measured = Omit<Shot, 'closedHeight' | 'disclosure'>;

async function measure(page: Page, width: number): Promise<Measured> {
    return page.evaluate((w) => {
        const q = (s: string) => document.querySelector(s);
        const r = (el: Element) => el.getBoundingClientRect();
        const round = (v: number) => Math.round(v * 10) / 10;

        const rail = q('#folderfolio-rail') as HTMLElement;
        const content = q('#wpbody-content') as HTMLElement;
        const title = q('.wp-heading-inline') as HTMLElement;
        const railBox = r(rail);

        /*
         * The top of whatever is actually drawn below the rail.
         *
         * `stacked` used to compare the rail's bottom with the top of
         * `#wpbody-content`, which was right while the rail was a *sibling* of
         * it. Below the breakpoint it is not: Rail.php's placement script
         * moves the rail into `.wrap`, under the title, which is where it was
         * asked to go — so the old measurement read "neither stacked nor side
         * by side" and called a correct layout an overlap.
         *
         * The first *following sibling with a box*, not simply the next one:
         * wp-admin prints `<div class="notice error hide-if-js">` right there,
         * and a zero-height hidden node reports top 0, which is above
         * everything. That is what the first attempt at this fix measured
         * against, and it failed in exactly the same place.
         */
        const below = [...rail.parentElement!.children]
            .slice([...rail.parentElement!.children].indexOf(rail) + 1)
            .map((el) => r(el))
            .filter((b) => b.width > 0 && b.height > 0)
            .reduce((top, b) => Math.min(top, b.top), Infinity);

        const tools = [...document.querySelectorAll('.folderfolio-rail__control')];

        // Anything of ours drawn outside the rail's own box. The collapsed
        // state is where this bites, but it is worth asking at every width.
        const spilling = [...rail.querySelectorAll('*')]
            .filter((el) => {
                const b = r(el);
                if (b.width === 0 || b.height === 0) return false;
                return b.right > railBox.right + 1 || b.left < railBox.left - 1;
            })
            .map((el) => (el.className && String(el.className).split(/\s+/)[0]) || el.tagName)
            .filter((c, i, a) => a.indexOf(c) === i)
            .slice(0, 6);

        return {
            width: w,
            docOverflowX:
                document.documentElement.scrollWidth - document.documentElement.clientWidth,
            clippedTools: tools
                .filter((t) => t.scrollWidth > t.clientWidth + 1)
                .map((t) => t.textContent!.trim()),
            spillingChildren: spilling,
            stacked: content.contains(rail) ? railBox.bottom <= below + 2 : railBox.bottom <= r(content).top + 2,
            sideBySide: railBox.right <= r(content).left + 2,
            railBeforeTitlePx: title ? round(r(title).top - railBox.top) : -1,
            toolWidths: tools.map((t) => round(r(t).width)),
            searchWidth: round(r(q('.folderfolio-rail__search')!).width),
        };
    }, width);
}

test.describe('the rail across viewport widths', () => {
    // Seven measurements plus a wp-admin boot on php-wasm.
    test.setTimeout(240_000);

    test('holds together at every width, and the breakpoint fires where the CSS says', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1600, height: 900 });
        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);
        await page.reload();
        await page.locator('#folderfolio-rail').waitFor();

        // A tree with some depth, so indentation and truncation are in play.
        const brand = await createFolder(page, 'Brand');
        const logos = await createFolder(page, 'Logos', brand.id);
        await createFolder(page, 'Primary', logos.id);
        await page.reload();
        await waitForTree(page, 'Brand');

        const shots: Shot[] = [];

        for (const width of WIDTHS) {
            await page.setViewportSize({ width, height: 900 });
            // Let flex and the media query settle before reading geometry.
            await page.waitForTimeout(400);

            /*
             * Below the breakpoint the rail loads closed, and everything the
             * measurements below look at is display:none inside it. So: record
             * the state the page actually loads in, photograph it, then open
             * the bar so that the furniture being compared across widths is
             * the same furniture at every one of them.
             */
            const closedHeight = await page.evaluate(() =>
                Math.round(document.getElementById('folderfolio-rail')!.getBoundingClientRect().height)
            );
            const disclosure = await page.evaluate(
                () =>
                    getComputedStyle(document.querySelector('.folderfolio-rail__tab')!).display
                    !== 'none'
            );

            await page.screenshot({ path: `test-results/responsive/${width}.png` });

            if (disclosure) {
                await page.locator('.folderfolio-rail__tab').click();
                await page.waitForTimeout(250);
            }

            shots.push({ ...(await measure(page, width)), closedHeight, disclosure });

            if (disclosure) {
                await page.screenshot({ path: `test-results/responsive/${width}-open.png` });
                await page.locator('[data-folderfolio-collapse]').click();
                await page.waitForTimeout(200);
            }
        }

        // eslint-disable-next-line no-console
        console.log(
            '\n  width   overflowX  layout        closed  railBeforeTitle  tools                 search\n'
                + shots
                    .map(
                        (s) =>
                            `  ${String(s.width).padEnd(6)}  ${String(s.docOverflowX).padEnd(9)}  `
                            + `${(s.stacked ? 'stacked' : s.sideBySide ? 'side-by-side' : 'overlapping').padEnd(12)}  `
                            + `${`${s.closedHeight}px`.padEnd(6)}  `
                            + `${String(s.railBeforeTitlePx).padEnd(15)}  `
                            + `${s.toolWidths.join('/').padEnd(20)}  ${s.searchWidth}`
                    )
                    .join('\n')
        );

        for (const s of shots) {
            // A page that scrolls sideways is wrong at every width.
            expect(s.docOverflowX, `horizontal overflow at ${s.width}px`).toBeLessThanOrEqual(1);

            // A button that hides its own label is wrong at every width.
            expect(s.clippedTools, `clipped toolbar labels at ${s.width}px`).toEqual([]);

            // Nothing of ours may render outside the rail.
            expect(s.spillingChildren, `rail children outside the rail at ${s.width}px`).toEqual([]);

            // The rail is either beside the library or above it, never neither.
            expect(
                s.stacked || s.sideBySide,
                `rail overlaps the library at ${s.width}px`
            ).toBe(true);
        }

        // The breakpoint is declared at 782px in _rail.css. 783 must be the
        // two-column layout and 782 the stacked one — a breakpoint that is off
        // by a pixel is a breakpoint nobody can reason about.
        const at783 = shots.find((s) => s.width === 783)!;
        const at782 = shots.find((s) => s.width === 782)!;

        expect(at783.sideBySide, '783px should still be two columns').toBe(true);
        expect(at782.stacked, '782px should stack').toBe(true);

        /*
         * And below it the rail is a disclosure rather than a panel: closed
         * when the page loads, a bar rather than a band.
         *
         * The second loop is the control, and it is the one that matters. A
         * rule that hid the rail at every width would satisfy the first loop
         * completely — it is only by asserting that 783px and up still get a
         * full-height column, and no bar to open, that the first loop means
         * "below the breakpoint" rather than "everywhere".
         */
        const phones = shots.filter((s) => s.disclosure);
        const desktops = shots.filter((s) => !s.disclosure);

        expect(
            phones.map((s) => s.width),
            'the bar belongs below the breakpoint and nowhere else'
        ).toEqual([782, 600, 390]);
        expect(desktops.map((s) => s.width)).toEqual([1600, 1280, 960, 783]);

        for (const s of phones) {
            // A row, not a band. The band this replaced was 375px, which put
            // the first thumbnail 1.34 screens down the page; the bar is 44px
            // plus the rule under it.
            expect(s.closedHeight, `the closed bar at ${s.width}px`).toBeLessThanOrEqual(56);
        }

        for (const s of desktops) {
            expect(s.closedHeight, `the rail column at ${s.width}px`).toBeGreaterThan(300);
        }

        /*
         * Stacked, the band's controls share the band — in equal parts.
         *
         * This used to assert the opposite: that every stacked width measured
         * what 783px measures, because a full-width band had made the four
         * toolbar buttons 201px each and the search field 748px, "a 300px
         * design spread across a phone". That was the right reading of the
         * wrong fix. Capping the contents at the rail's desktop width left the
         * action row as four small buttons adrift in a 736px bar, which is
         * what "what happened to actions? sizes are quite off" was about.
         *
         * The band is not a narrow rail laid on its side; it is its own shape.
         *
         * That shape changed on 22 Sep. There were four equal quarters —
         * Rename, Delete, Sort, More — and the assertion here was that they
         * divided the band evenly. The toolbar is gone: the folder actions
         * moved onto the selected row's ⋮ above 782px, and below it the two
         * survivors sit at the end of the search line, which is the only row
         * of controls the rail has left.
         *
         * So what is asserted now is that line: **two** square controls, both
         * a real size, with the search field taking everything else. Sort is
         * one of them at every width; More is the other, and it exists only
         * here, because `LevelRow` is a single button and cannot hold a ⋮.
         */
        for (const s of shots.filter((shot) => shot.stacked)) {
            expect(
                s.toolWidths.length,
                `the band should carry Sort and More and nothing else at ${s.width}px`
            ).toBe(2);

            // Square, and the same height as the field beside them. A control
            // that has been squeezed narrower than it is tall is the failure
            // this catches, and it is the one a flex row produces silently.
            for (const w of s.toolWidths) {
                expect(
                    w,
                    `a control is narrower than its own height at ${s.width}px`
                ).toBeGreaterThanOrEqual(30);
            }

            // The search takes the band rather than sitting in a 274px box
            // inside it — the same decision, one row down. Measured against
            // the wider of the two controls beside it.
            const widest = Math.max(...s.toolWidths);

            expect(
                s.searchWidth,
                `the search field does not fill the band at ${s.width}px`
            ).toBeGreaterThan(widest * 3);
        }
    });

    /**
     * The toolbar against the rail's own width, which the viewport sweep
     * cannot see: a fresh Playground opens at the 300px default, and the rail
     * is resizable down to 240.
     *
     * What was wrong here was not the label. `.folderfolio-rail__tool svg` had
     * no `flex: 0 0 auto`, so a button short of room shrank its **icon to zero
     * width** before clipping anything else — which is why Rename and Delete
     * looked iconless while Sort and More, which had slack, did not. With the
     * icon back, four labelled buttons need a 300px rail exactly, so below
     * that the labels are hidden the wp-admin way and the icons stay.
     *
     * So this now asserts two things, and the second is the one that was
     * missed: nothing is clipped, **and** every icon is still 14px wide. A
     * button can always avoid clipping by crushing its contents to nothing.
     */
    test('the toolbar labels fit at every width the rail can be dragged to', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();

        const min = await page.evaluate(() => window.folderFolio?.rail?.minWidth ?? 240);
        const widths = [min, 260, 280, 300, 360];
        const clipped: Record<number, string[]> = {};
        const crushed: Record<number, string[]> = {};

        for (const w of widths) {
            await page.evaluate((width) => {
                document
                    .getElementById('folderfolio-rail')!
                    .style.setProperty('--ff-rail-w', `${width}px`);
            }, w);
            await page.waitForTimeout(250);

            clipped[w] = await page.evaluate(() =>
                [...document.querySelectorAll('.folderfolio-rail__tool')]
                    .filter((t) => t.scrollWidth > t.clientWidth + 1)
                    .map((t) => t.textContent!.trim())
            );

            crushed[w] = await page.evaluate(() =>
                [...document.querySelectorAll('.folderfolio-rail__tool')]
                    .filter((t) => {
                        const icon = t.querySelector('svg');

                        return !icon || Math.round(icon.getBoundingClientRect().width) < 14;
                    })
                    .map((t) => t.getAttribute('title') ?? t.textContent!.trim())
            );

            await page
                .locator('#folderfolio-rail')
                .screenshot({ path: `test-results/responsive/rail-${w}.png` });
        }

        // eslint-disable-next-line no-console
        console.log(
            '\n  rail width   clipped labels   crushed icons\n'
                + widths
                    .map(
                        (w) =>
                            `  ${String(w).padEnd(11)}  ${(clipped[w].join(', ') || '—').padEnd(15)}  `
                            + (crushed[w].join(', ') || '—')
                    )
                    .join('\n')
        );

        for (const w of widths) {
            expect(clipped[w], `labels clipped at a ${w}px rail`).toEqual([]);
            expect(crushed[w], `icon shrunk below 14px at a ${w}px rail`).toEqual([]);
        }
    });

    /**
     * The phone disclosure, end to end.
     *
     * Three things have to be true together, and each of them was wrong at
     * some point while this was being built:
     *
     * 1. It is closed on load — and closed *by CSS*, from a selector that
     *    matches before any script runs, so a phone never paints the 375px
     *    band first. Asserted as the absence of the class rather than as its
     *    presence, which is the only way the "before JS" part is testable
     *    from here at all.
     * 2. A tap on the row opens it — the row, not the 24px button inside it,
     *    because the row is the tap target the platform asks for.
     * 3. Neither of those touches the stored preference. `open` is what the
     *    user chose on their desktop; a narrow window is not a choice. This
     *    is checked by reloading: if the tap had been persisted, the bar would
     *    come back open.
     *
     * And throughout: the folder select on the toolbar's filter line is
     * present and complete, because it, not the bar, is what carries
     * navigation at this width. The bar is for managing folders.
     */
    test('below the breakpoint the rail is a bar that opens on a tap, and forgets it', async ({
        page,
    }) => {
        /*
         * The fixture is built at desktop width, because building it means
         * seeing the tree and at 390px the tree is behind the bar — which is
         * the feature. Then the viewport shrinks and the page is loaded
         * again, so that what follows is a real load at phone width and not a
         * resize of a page that opened wide.
         */
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);
        await createFolder(page, 'Brand');
        await page.reload();
        await waitForTree(page, 'Brand');

        await page.setViewportSize({ width: 390, height: 844 });
        await page.reload();
        await page.locator('#folderfolio-rail').waitFor();

        const rail = page.locator('#folderfolio-rail');
        const bar = page.locator('.folderfolio-rail__tab');
        // By id, not by class: the server prints the shell with this class and
        // React renders a second element with the same class inside it, so
        // `.folderfolio-rail__app` matches two nested nodes. (Worth tidying;
        // it is not this change's business.)
        const tree = page.locator('#folderfolio-rail-app');

        // 1. Closed on load.
        await expect(bar).toBeVisible();
        await expect(tree).toBeHidden();
        await expect(page.locator('body.folderfolio-rail-peek')).toHaveCount(0);
        await expect(page.locator('[data-folderfolio-expand]')).toHaveAttribute(
            'aria-expanded',
            'false'
        );

        /*
         * Navigation does not depend on opening it — but not through the
         * select any more.
         *
         * This test used to assert that the whole tree was on
         * `#folderfolio-folder-filter`, indented, with counts, and that was
         * the narrow layout's written premise. Against 1,050 folders that
         * select holds 1,055 options and 24,027 characters: a native picker a
         * thousand rows long. Below the breakpoint it now steps aside for a
         * button that opens a searchable, capped panel.
         *
         * The select is still in the document — it is the no-script path in
         * list mode — so this asserts it is hidden rather than absent.
         */
        const select = page.locator('#folderfolio-folder-filter');
        const picker = page.locator('.folderfolio-folder-picker');

        await expect(select).toBeHidden();

        // The picker lives among the collapsed filters, so the disclosure has
        // to be opened before it is on screen — which is itself the contract:
        // one button stands for the filters at this width.
        await page.getByRole('button', { name: /^filters$/i }).click();
        await expect(picker).toBeVisible();
        await expect(picker).toHaveText(/all media/i);

        await picker.click();

        const panel = page.getByRole('dialog', { name: /filter by folder/i });
        await expect(panel).toBeVisible();
        // Focus is in the search field: at a thousand folders typing is the
        // way in, and it is the first thing the panel offers.
        await expect(panel.getByRole('searchbox')).toBeFocused();
        await expect(panel.getByRole('option', { name: /brand/i })).toBeVisible();

        await panel.getByRole('option', { name: /brand/i }).click();
        await expect(panel).toBeHidden();
        await expect(picker).toHaveText(/brand/i);
        await expect(page).toHaveURL(/folderfolio_folder=\d+/);

        /*
         * Put the filters back the way they were, so what follows is the
         * sheet's own behaviour rather than this paragraph's leftovers.
         *
         * A prefix match, not an exact one: a folder is filtered now, so the
         * button carries its count badge and the screen-reader sentence that
         * spells the badge out — "Filters 1 filter active" — and its
         * accessible name is no longer just the label.
         */
        await page.getByRole('button', { name: /^filters/i }).click();

        // 2. A tap on the row opens it.
        await bar.click();
        await expect(tree).toBeVisible();
        await expect(bar).toBeHidden();
        await expect(page.locator('[data-folderfolio-collapse]')).toHaveAttribute(
            'aria-expanded',
            'true'
        );
        await page.screenshot({ path: 'test-results/responsive/phone-open.png' });

        // Open, it is still the rail: New folder and the control line are
        // there, which is the point of having a bar rather than hiding the
        // rail outright. Two controls, not the old four — Sort, and the More
        // that only this width has.
        await expect(page.getByRole('button', { name: /new folder/i })).toBeVisible();
        await expect(page.locator('.folderfolio-rail__control')).toHaveCount(2);

        // Collapse closes it again, and focus lands on the control that
        // replaces the one that just disappeared.
        await page.locator('[data-folderfolio-collapse]').click();
        await expect(tree).toBeHidden();
        await expect(page.locator('.folderfolio-rail__expand')).toBeFocused();

        // 3. None of that was written down.
        await bar.click();
        await expect(tree).toBeVisible();
        await page.reload();
        await page.locator('#folderfolio-rail').waitFor();
        await expect(page.locator('.folderfolio-rail__tab')).toBeVisible();
        await expect(tree).toBeHidden();

        // The control for that last clause: widen, and the desktop preference
        // is exactly where it was left — open, a column, no bar. A tap that
        // had persisted would show up here as a collapsed desktop rail.
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.waitForTimeout(400);
        await expect(page.locator('.folderfolio-rail__tab')).toBeHidden();
        await expect(rail).not.toHaveClass(/is-collapsed/);
        await expect(tree).toBeVisible();
    });

    /**
     * The sheet walks one level at a time below the breakpoint.
     *
     * Measured on a 1,050-folder fixture at 528px: the open sheet gave the
     * tree 190px against a 50,976px tree — 0.37% of it on screen. Drill-down
     * changes what is in that window rather than how big it is; a level there
     * averages 3.7 rows.
     *
     * What this asserts is the part that is a *contract* rather than a
     * measurement: one level at a time, a way back out that does not re-filter
     * the library, and a tapped row that does both — filters, and walks in.
     */
    test('the sheet shows one level at a time, and back does not re-filter', async ({
        page,
    }) => {
        // Built wide, for the reason the test above gives: seeding means
        // seeing the tree, and at 390px the tree is behind the bar.
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);

        const brand = await createFolder(page, 'Brand');
        await createFolder(page, 'Logos', brand.id);
        await createFolder(page, 'Packaging', brand.id);
        await createFolder(page, 'Audio');

        await page.setViewportSize({ width: 390, height: 844 });
        await page.reload();
        await page.locator('#folderfolio-rail').waitFor();
        await page.locator('.folderfolio-rail__tab').click();

        const levels = page.locator('.folderfolio-levels');
        const rows = page.locator('.folderfolio-levels__row');

        await expect(levels).toBeVisible();
        // The desktop tree is not rendered at all down here — one navigation
        // model at a time, never two sets of rows claiming the same folders.
        await expect(page.locator('[role="tree"]')).toHaveCount(0);

        // The top level: the roots, and nothing from inside them.
        await expect(rows).toHaveText([/Audio/, /Brand/]);

        // A tap filters the library *and* walks in.
        await rows.filter({ hasText: 'Brand' }).click();
        await expect(page).toHaveURL(new RegExp(`folderfolio_folder=${brand.id}\\b`));
        await expect(rows).toHaveText([/Logos/, /Packaging/]);
        await expect(page.locator('.folderfolio-levels__here')).toHaveText(/Brand/);

        // Back goes up without asking the server for anything: the URL is
        // still the folder that was chosen.
        await page.locator('.folderfolio-levels__up').click();
        await expect(rows).toHaveText([/Audio/, /Brand/]);
        await expect(page).toHaveURL(new RegExp(`folderfolio_folder=${brand.id}\\b`));
        await expect(
            rows.filter({ hasText: 'Brand' })
        ).toHaveAttribute('aria-current', 'true');
        // The current row's name is the one in bold. The row's own 600 never
        // reached it — the name sets 400 — until the rule said it on the name.
        await expect(
            rows.filter({ hasText: 'Brand' }).locator('.folderfolio-row__name')
        ).toHaveCSS('font-weight', '600');
        await expect(
            rows.filter({ hasText: 'Audio' }).locator('.folderfolio-row__name')
        ).toHaveCSS('font-weight', '400');

        // …and above the breakpoint the tree is back, untouched.
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.waitForTimeout(400);
        await expect(page.locator('[role="tree"]')).toHaveCount(1);
        await expect(page.locator('.folderfolio-levels')).toHaveCount(0);
    });

    /**
     * The band that answers "where am I" meets the hairline it sits under.
     *
     * `.folderfolio-rail__body` carried `padding: 6px 0 12px`, which pushed the
     * band — and with it the selected-path bar drawn inside its left edge — six
     * pixels below the search rule, so the bar read as too short for its row.
     * The sticky `top: -6px` had cancelled those six for the *stuck* case only;
     * at the top of the scroll nothing is stuck and the padding is simply
     * there, which is the state the screenshot that reported this was taken in.
     *
     * So: both states, because the bug was that the two disagreed. One pixel
     * apart, not zero — the scroller's own top border is between them.
     */
    test('the pinned level band meets the hairline, stuck or not', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);

        const brand = await createFolder(page, 'Brand');
        // Enough children to overflow a 844px-tall phone's rail, so that the
        // band has something to be stuck over.
        for (let i = 1; i <= 12; i += 1) {
            await createFolder(page, `Child ${i}`, brand.id);
        }

        await page.setViewportSize({ width: 390, height: 844 });
        await page.reload();
        await page.locator('#folderfolio-rail').waitFor();
        await page.locator('.folderfolio-rail__tab').click();
        await page.locator('.folderfolio-levels__row').filter({ hasText: 'Brand' }).click();

        const band = page.locator('.folderfolio-levels__head');
        await expect(band).toBeVisible();

        // Since 25 Sep (option A) the band sits under the pinned search line,
        // inside one scroller with it, and meets the line's bottom rule.
        const gap = async () =>
            page.evaluate(() => {
                const scroller = document.querySelector('.folderfolio-rail__scroll');
                const controls = document.querySelector('.folderfolio-rail__controls');
                const head = document.querySelector('.folderfolio-levels__head');
                if (!(scroller instanceof HTMLElement) || !(controls instanceof HTMLElement) || !(head instanceof HTMLElement)) {
                    return null;
                }
                return {
                    gap: head.getBoundingClientRect().top - controls.getBoundingClientRect().bottom,
                    pinned: controls.getBoundingClientRect().top - scroller.getBoundingClientRect().top,
                    scrollable: scroller.scrollHeight - scroller.clientHeight,
                };
            });

        const atRest = await gap();
        expect(atRest).not.toBeNull();
        expect(atRest!.gap).toBeCloseTo(0, 1);
        // The state the fix is *about* only exists if there is a scroll to be
        // at the top of; if this ever goes to zero the assertion below is vacuous.
        expect(atRest!.scrollable).toBeGreaterThan(0);

        await page.evaluate(() => {
            const scroller = document.querySelector('.folderfolio-rail__scroll')!;
            scroller.scrollTop = 120;
            scroller.dispatchEvent(new Event('scroll'));
        });
        await page.waitForTimeout(150);

        const stuck = await gap();
        expect(stuck!.gap).toBeCloseTo(0, 1);
        expect(stuck!.pinned).toBeCloseTo(0, 1);
    });

    /**
     * Collapsing hid `__body` and `__footer` and left the header, toolbar,
     * fixed rows and search field rendering inside a 28px column with
     * `overflow: visible` — so they drew across the page, and the reopen tab
     * was pushed 443px down by the siblings still above it. It survived a
     * reload, because collapsed is a stored preference.
     *
     * Two fixes made this pass: .is-collapsed hides the whole `__app`, and
     * core's #wpfooter is indented past the rail — before that it lay over the
     * Collapse button and Playwright would not click it, so the test could not
     * even reach the thing it was testing.
     */
    test('collapsed, nothing renders outside the 28px tab', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/wp-admin/upload.php?mode=grid');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);
        await createFolder(page, 'Brand');
        await page.reload();
        await waitForTree(page, 'Brand');

        await page.locator('.folderfolio-rail__collapse').click();
        await expect(page.locator('#folderfolio-rail.is-collapsed')).toBeVisible();

        try {
            await measureCollapsed(page);
        } finally {
            // Open/closed is a stored preference. Left closed, every spec
            // after this one meets a 28px tab where it expects a tree — the
            // suite's first full run lost two settings specs to exactly that.
            //
            // Through the tab, not a direct POST: rail.ts debounces its own
            // write by 400ms, so a POST from here could land first and be
            // overwritten by the collapse. Waiting for the write that says
            // `open: true` is waiting for the last word.
            const reopened = page.waitForResponse(
                (response) =>
                    response.url().includes('folderfolio/v1/preferences') &&
                    response.request().method() === 'POST' &&
                    response.request().postDataJSON()?.rail?.open === true
            );
            await page.locator('.folderfolio-rail__expand').click();
            await reopened;
        }
    });
});

/**
 * The collapsed rail's measurements — split out so the test above can put
 * the rail back open whether these pass or not.
 */
async function measureCollapsed(page: Page): Promise<void> {
    await page.screenshot({ path: 'test-results/responsive/collapsed.png' });

    const collapsed = await page.evaluate(() => {
        const rail = document.querySelector('#folderfolio-rail') as HTMLElement;
        const box = rail.getBoundingClientRect();
        const tab = document.querySelector('.folderfolio-rail__tab') as HTMLElement;

        const outside = [...rail.querySelectorAll('*')]
            .filter((el) => {
                const b = el.getBoundingClientRect();
                if (b.width === 0 || b.height === 0) return false;
                return b.right > box.right + 1 || b.left < box.left - 1;
            })
            .map((el) => String(el.className).split(/\s+/)[0] || el.tagName)
            .filter((c, i, a) => a.indexOf(c) === i);

        return {
            width: Math.round(box.width),
            outside,
            tabOffsetFromTop: Math.round(tab.getBoundingClientRect().top - box.top),
        };
    });

    expect(collapsed.width, 'the collapsed rail is a 28px tab').toBe(28);
    expect(collapsed.outside, 'nothing may render outside the collapsed rail').toEqual([]);

    // 14px of padding-top and no more: the board's collapsed rail holds
    // the reopen button and the label, and nothing above them.
    expect(
        collapsed.tabOffsetFromTop,
        'the reopen tab belongs at the top of the rail'
    ).toBeLessThanOrEqual(16);
}

