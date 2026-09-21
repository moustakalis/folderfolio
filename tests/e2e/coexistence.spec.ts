import { expect, test } from '@playwright/test';

import { createFolder, resetFolders, waitForTree } from './helpers/folders';
import {
    claimsTheContentPadding,
    refetchesTheContentColumn,
    takesTheUploaderSlot,
    type NeighbourState,
} from './helpers/bad-neighbour';

/**
 * What happens when somebody else is on the same screen.
 *
 * Both bugs these guard were found by hand on 21 Sep, on a dev site with four
 * rivals installed, and neither was reachable from this suite: the harness
 * installs no folder plugin but ours. Both lose the user's work with no error
 * on screen, which is the class of defect a green suite is worst at.
 *
 * The neighbour is synthetic — see helpers/bad-neighbour.ts for what it
 * reproduces and why it is not FileBird.
 *
 * Each test asserts its own precondition first. A guard against "somebody took
 * the slot" that runs on a page where nobody took the slot is a guard that
 * passes by doing nothing, which this project has shipped once already.
 */

const marker = (): boolean =>
    (
        window as unknown as {
            wp?: { Uploader?: { prototype?: { init?: { folderfolioUploadTarget?: boolean } } } };
        }
    ).wp?.Uploader?.prototype?.init?.folderfolioUploadTarget === true;

const neighbour = (): NeighbourState =>
    (window as unknown as { __badNeighbour: NeighbourState }).__badNeighbour;

test.describe('sharing the media library with a hostile neighbour', () => {
    /**
     * The whole lesson of `9cff4ad`, which passed FileBird, passed CatFolders
     * and failed both together: where the failure mode is "last writer wins",
     * one writer is not a test of it.
     *
     * Three writers at three stages, each registered at document-start so it
     * is ahead of anything we can enqueue. `interactive` is where a deferred
     * bundle calling `wp.domReady()` lands, `dcl` is a plain listener, and
     * `load` is later than either — and it is the one the old fix had no
     * answer to at all, because its only re-assertion was a macrotask queued
     * before `DOMContentLoaded` had even been queued.
     */
    test('the upload wrap comes back after three neighbours take the slot', async ({ page }) => {
        await takesTheUploaderSlot(page, ['interactive', 'dcl', 'load']);

        await page.goto('/wp-admin/upload.php');
        await page.locator('#folderfolio-rail').waitFor();

        // The precondition: the neighbour really did take it, three times.
        await page.waitForFunction(
            () =>
                (window as unknown as { __badNeighbour: { assigned: string[] } }).__badNeighbour
                    .assigned.length === 3
        );

        expect(await page.evaluate(neighbour)).toEqual({
            assigned: ['interactive', 'dcl', 'load'],
        });

        // And the wrap is ours again afterwards.
        await page.waitForFunction(marker);

        // Not by discarding theirs: re-wrapping wraps *their* init, so the
        // neighbour keeps working. A fix that fixed us by breaking them would
        // pass the line above.
        const chainsThrough = await page.evaluate(
            () =>
                typeof (
                    window as unknown as {
                        wp: { Uploader: { prototype: { init: unknown } } };
                    }
                ).wp.Uploader.prototype.init === 'function'
        );

        expect(chainsThrough).toBe(true);
    });

    /**
     * A neighbour that patches later than every retry.
     *
     * A fixed sequence of delays answers the four plugins on the market today.
     * It cannot answer one that patches on a timer, or when a media modal
     * opens. The first pointer or key event is the last moment before anybody
     * can have started an upload, and this is the only thing guarding it.
     */
    test('a neighbour patching after the last retry is answered by the first interaction', async ({
        page,
    }) => {
        await takesTheUploaderSlot(page, ['late']);

        await page.goto('/wp-admin/upload.php');
        await page.locator('#folderfolio-rail').waitFor();

        await page.waitForFunction(
            () =>
                (window as unknown as { __badNeighbour: { assigned: string[] } }).__badNeighbour
                    .assigned[0] === 'late'
        );

        // Confirm the fixture can produce the failure before believing the
        // fix: at this instant the slot is theirs and nothing of ours is
        // scheduled to take it back.
        expect(await page.evaluate(marker)).toBe(false);

        await page.mouse.move(400, 400);
        await page.mouse.down();
        await page.mouse.up();

        await page.waitForFunction(marker);
    });

    /**
     * Premio's list-mode folder click, which is `jQuery('#wpbody').load(url + '
     * #wpbody-content')`.
     *
     * The rail is not destroyed by it. The fetched content column carries a
     * *fresh copy* of our server-rendered markup, because Rail.php prints on
     * `all_admin_notices`, so a rail comes back — un-relocated, still carrying
     * the `hidden` our own `display: flex` cancels, and with an empty React
     * root. Measured in a 700px viewport it drew 668px of dead panel and
     * pushed the file table to `top: 953px`: a blank media library.
     *
     * So the assertions below are about identity and about the mounted app,
     * not about an element existing. `getElementById` finds a rail in the
     * broken state too.
     */
    test('the live rail survives a neighbour refetching the content column', async ({ page }) => {
        await refetchesTheContentColumn(page);

        await page.goto('/wp-admin/upload.php');
        await page.locator('#folderfolio-rail').waitFor();
        await resetFolders(page);
        await page.reload();

        await createFolder(page, 'Neighbours');
        await page.reload();
        await waitForTree(page, 'Neighbours');

        // Stamped on the live node only. A resurrected copy comes from the
        // server and cannot carry it.
        await page.evaluate(() =>
            document.getElementById('folderfolio-rail')?.setAttribute('data-ff-live', 'yes')
        );

        const before = await page.evaluate(() => ({
            children: [...(document.getElementById('wpbody')?.children ?? [])].map(
                (el) => el.id || el.className
            ),
            treeitems: document.querySelectorAll('#folderfolio-rail [role="treeitem"]').length,
        }));

        expect(before.children.slice(0, 3)).toEqual([
            'folderfolio-rail',
            'folderfolio-rail-handle',
            'wpbody-content',
        ]);
        expect(before.treeitems).toBeGreaterThan(0);

        await page.evaluate(() =>
            (window as unknown as { __badNeighbourRefetch: () => Promise<void> })
                .__badNeighbourRefetch()
        );

        // The repair runs in a microtask after the swap, so wait for it rather
        // than reading straight through.
        await page.waitForFunction(
            () => document.querySelectorAll('#folderfolio-rail').length === 1
        );

        const after = await page.evaluate(() => {
            const rail = document.getElementById('folderfolio-rail');
            const app = document.getElementById('folderfolio-rail-app');

            return {
                rails: document.querySelectorAll('#folderfolio-rail').length,
                handles: document.querySelectorAll('#folderfolio-rail-handle').length,
                isTheLiveNode: rail?.getAttribute('data-ff-live') === 'yes',
                parent: rail?.parentElement?.id ?? null,
                children: [...(document.getElementById('wpbody')?.children ?? [])].map(
                    (el) => el.id || el.className
                ),
                // Trap 50: check for the mounted app, not the element.
                appChildren: app?.childElementCount ?? 0,
                treeitems: document.querySelectorAll('#folderfolio-rail [role="treeitem"]').length,
            };
        });

        // The swap really happened: the content column is a different node.
        expect(await page.evaluate(() => document.querySelectorAll('#wpbody-content').length)).toBe(
            1
        );

        expect(after.rails).toBe(1);
        expect(after.handles).toBe(1);
        expect(after.isTheLiveNode).toBe(true);
        expect(after.parent).toBe('wpbody');
        expect(after.children.slice(0, 3)).toEqual([
            'folderfolio-rail',
            'folderfolio-rail-handle',
            'wpbody-content',
        ]);
        expect(after.appChildren).toBeGreaterThan(0);
        expect(after.treeitems).toBeGreaterThan(0);
    });

    /**
     * The seam, and who is allowed to win it.
     *
     * Library finding 5 closed a 20px band of page background between the dark
     * admin menu and the white rail. It did that by zeroing `#wpcontent`'s
     * inline-start padding — a property on an element we do not own, which
     * Premio's Folders also writes, at identical specificity, to reserve room
     * for its own `position: fixed` rail. Ours loaded later and won, so
     * nothing reserved the room and their rail was drawn on top of ours.
     * **Winning was the bug.**
     *
     * Two assertions, because either alone passes for the wrong reason: a
     * build that simply dropped finding 5 would satisfy the second, and the
     * old code satisfied the first.
     */
    test('the seam closes against core, and yields to a neighbour that claims it', async ({
        page,
    }) => {
        const geometry = () =>
            page.evaluate(() => {
                const box = (el: Element | null): DOMRect | null =>
                    el ? el.getBoundingClientRect() : null;
                const rail = document.getElementById('folderfolio-rail');
                const menu =
                    document.getElementById('adminmenuwrap') ??
                    document.getElementById('adminmenu');
                const wpcontent = document.getElementById('wpcontent');
                const footer = document.getElementById('wpfooter');

                return {
                    hidden: document.hidden,
                    width: window.innerWidth,
                    padding: wpcontent
                        ? window.getComputedStyle(wpcontent).paddingInlineStart
                        : null,
                    railLeft: Math.round(box(rail)?.left ?? -1),
                    railRight: Math.round(box(rail)?.right ?? -1),
                    menuRight: Math.round(box(menu)?.right ?? -1),
                    footerTextLeft: footer
                        ? Math.round(
                              (box(footer)?.left ?? 0) +
                                  parseFloat(window.getComputedStyle(footer).paddingLeft)
                          )
                        : -1,
                    sideways:
                        document.documentElement.scrollWidth >
                        document.documentElement.clientWidth,
                };
            });

        await page.goto('/wp-admin/upload.php');
        await page.locator('#folderfolio-rail').waitFor();

        const alone = await geometry();

        // Wide enough for the rail to be a column rather than a band, or none
        // of this applies.
        expect(alone.width).toBeGreaterThan(782);

        // We no longer write to it: core's own padding is still there …
        expect(alone.padding).not.toBe('0px');
        // … and the seam is closed anyway, from our own element.
        expect(alone.railLeft).toBe(alone.menuRight);
        // The footer still clears the rail — library finding 2.
        expect(alone.footerTextLeft).toBeGreaterThanOrEqual(alone.railRight);
        expect(alone.sideways).toBe(false);

        // Now a neighbour reserves 305px for a rail of its own, at Premio's
        // exact selector and value, present before our script reads it.
        await claimsTheContentPadding(page);
        await page.goto('/wp-admin/upload.php');
        await page.locator('#folderfolio-rail').waitFor();

        const beside = await geometry();

        // The precondition: their claim is in force and we did not overwrite
        // it. Without this the rest passes on a page where nobody claimed
        // anything.
        expect(beside.padding).toBe('305px');

        // Their band runs from the menu's edge to 305px past it. We start
        // after it, not on it.
        expect(beside.railLeft).toBeGreaterThanOrEqual(beside.menuRight + 305);
        expect(beside.footerTextLeft).toBeGreaterThanOrEqual(beside.railRight);
        expect(beside.sideways).toBe(false);
    });
});
