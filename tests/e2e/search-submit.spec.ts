import { expect, test } from '@playwright/test';

/**
 * Can list mode's `Search Media` button be hidden?
 *
 * The two modes draw their search corner in opposite directions — grid shows a
 * visible `<label>` and no button, list a hidden label and a visible submit —
 * and every option for aligning them ends at the same question: if the button
 * goes, does Enter still submit?
 *
 * The reasoning says yes. `#posts-filter` is a GET form whose submit controls,
 * in tree order, are `#post-query-submit` ("Filter"), then `#search-submit`,
 * then the two `Apply`s — so the form's *default button*, the one implicit
 * submission activates, is Filter and never was the search button. Hiding an
 * element does not remove it from tree order, so nothing about Enter should
 * change.
 *
 * That reasoning is not a result, and an attempt to confirm it in an automated
 * browser measured the automation instead: Enter failed to submit in the
 * shipping state too, which means the run said nothing about either state. So
 * the control below is not optional decoration — **without it a green "hidden"
 * test proves nothing**, because a harness that cannot deliver Enter at all
 * would fail both and a harness that can would pass both. The pair is the
 * evidence; either one alone is a coin toss reported as a fact.
 */

const PROBE = 'ff-enter-probe';

test.describe('list mode submits its search from the keyboard', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/wp-admin/upload.php?mode=list');
        await page.locator('#posts-filter').waitFor();
    });

    test('the form default button is Filter, not the search button', async ({ page }) => {
        // The whole argument for hiding #search-submit rests on this ordering.
        // If a future WordPress reorders the toolbar, this fails here rather
        // than as an unexplained "search stopped working" somewhere else.
        const submits = await page.evaluate(() =>
            [
                ...document.querySelectorAll<HTMLElement>(
                    '#posts-filter input[type=submit], #posts-filter button[type=submit]'
                ),
            ].map((element) => element.id)
        );

        expect(submits[0]).toBe('post-query-submit');
        expect(submits).toContain('search-submit');
    });

    test('CONTROL: Enter submits while the search button is visible', async ({ page }) => {
        // If this fails, the harness cannot deliver Enter to this page and the
        // next test's result — pass or fail — means nothing. Read them together.
        await expect(page.locator('#search-submit')).toBeVisible();

        await page.locator('#media-search-input').fill(PROBE);
        await page.locator('#media-search-input').press('Enter');

        await page.waitForURL(new RegExp(`s=${PROBE}`));
        expect(page.url()).toContain(`s=${PROBE}`);
    });

    test('Enter still submits with the search button hidden', async ({ page }) => {
        await page.addStyleTag({ content: '#search-submit { display: none !important; }' });
        await expect(page.locator('#search-submit')).toBeHidden();

        await page.locator('#media-search-input').fill(PROBE);
        await page.locator('#media-search-input').press('Enter');

        await page.waitForURL(new RegExp(`s=${PROBE}`));
        expect(page.url()).toContain(`s=${PROBE}`);

        // And the search actually applied, rather than the form merely having
        // navigated somewhere: the field comes back carrying what was searched.
        await expect(page.locator('#media-search-input')).toHaveValue(PROBE);
    });
});
