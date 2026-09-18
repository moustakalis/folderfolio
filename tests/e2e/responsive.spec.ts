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
 * and not one of them is narrow, so there is no specification for what the
 * rail should *be* on a phone; asserting a number here would be inventing one
 * and freezing it. What is checked instead is the set of things that are
 * wrong at any width under any design: a page that scrolls sideways, a label
 * clipped by its own button, a child rendering outside its parent, a
 * breakpoint that does not fire where its own CSS says it does.
 *
 * The undesigned figures are measured and printed rather than asserted, so
 * the numbers are in the run output when the design question is answered.
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
}

async function measure(page: Page, width: number): Promise<Shot> {
    return page.evaluate((w) => {
        const q = (s: string) => document.querySelector(s);
        const r = (el: Element) => el.getBoundingClientRect();
        const round = (v: number) => Math.round(v * 10) / 10;

        const rail = q('#folderfolio-rail') as HTMLElement;
        const content = q('#wpbody-content') as HTMLElement;
        const title = q('.wp-heading-inline') as HTMLElement;
        const railBox = r(rail);

        const tools = [...document.querySelectorAll('.folderfolio-rail__tool')];

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
            stacked: railBox.bottom <= r(content).top + 2,
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
            shots.push(await measure(page, width));
            await page.screenshot({ path: `test-results/responsive/${width}.png` });
        }

        // eslint-disable-next-line no-console
        console.log(
            '\n  width   overflowX  layout        railBeforeTitle  tools                 search\n'
                + shots
                    .map(
                        (s) =>
                            `  ${String(s.width).padEnd(6)}  ${String(s.docOverflowX).padEnd(9)}  `
                            + `${(s.stacked ? 'stacked' : s.sideBySide ? 'side-by-side' : 'overlapping').padEnd(12)}  `
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
    });
});
