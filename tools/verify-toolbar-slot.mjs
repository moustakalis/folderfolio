/**
 * Proves that a React portal can live in WordPress's toolbar and survive it
 * being rebuilt.
 *
 * lib/toolbar-slot.ts exists because both of the library's toolbars are
 * replaced out from under anything mounted into them — list mode's on every
 * folder change, grid mode's whenever the media frame re-renders. Its claim is
 * that moving one element that React owns, rather than re-creating it,
 * preserves the React subtree inside.
 *
 * That claim is not checkable by a type-check, and three of its four parts are
 * invisible in a passing screenshot:
 *
 *   - the element comes back at all;
 *   - the React component inside it is the *same* component instance, not a
 *     remount — a remount looks identical and silently discards an open
 *     flyout, a half-typed search and an in-flight request;
 *   - the DOM node is the same node, so an <input> keeps what was typed in it;
 *   - focus, which the browser drops to <body> on any detach, comes back.
 *
 * Focus in particular cannot be checked in an automated Chrome tab driven over
 * CDP: focus events are not delivered there reliably, which is the same class
 * of environment gap that made requestAnimationFrame and ResizeObserver
 * useless for verifying the breadcrumb. So it is checked here, in a real
 * browser this script drives itself.
 *
 *   node tools/verify-toolbar-slot.mjs
 */

import assert from 'node:assert/strict';
import { mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

import * as esbuild from 'esbuild';
import { chromium } from '@playwright/test';

import { ALIAS, ROOT, SRC } from './esbuild.mjs';

/**
 * The fixture, importing the shipping module — not a copy of it.
 *
 * The import is an absolute path, patched in below: the fixture is written to
 * a temp directory, so anything relative would resolve against /tmp.
 *
 * The button counts its own clicks and holds text in an input, because those
 * are the two things a remount would reset and a move would not. Nothing here
 * is a FolderFolio component: this is a test of the slot, and coupling it to
 * the flyout's markup would make it fail for reasons that are not the slot's.
 */
const APP = `
import { useState } from 'react';
import { createPortal } from 'react-dom';
import { createRoot } from 'react-dom/client';

import { bulkSlotPlace, useToolbarSlot } from '__SLOT__';

let mounts = 0;

function Control() {
    // Counted at first render of this instance. A remount starts a new
    // instance, so this number is the assertion.
    const [instance] = useState(() => ++mounts);
    const [clicks, setClicks] = useState(0);

    return (
        <>
            <button id="ff-button" type="button" onClick={() => setClicks((n) => n + 1)}>
                {instance}:{clicks}
            </button>
            <input id="ff-input" type="text" defaultValue="" />
        </>
    );
}

function App() {
    /*
     * The real LibraryToolbar re-renders whenever the folder tree changes,
     * which is after every mutation — so the test has to be able to make that
     * happen. Without it an implementation that built a fresh slot element on
     * every render would pass this file, because nothing would ever ask it to
     * render twice.
     */
    const [, bump] = useState(0);
    window.__ffRerender = () => bump((n) => n + 1);

    const slot = useToolbarSlot(bulkSlotPlace, 'bulk');

    return slot ? createPortal(<Control />, slot) : null;
}

createRoot(document.getElementById('root')).render(<App />);
`;

const HARNESS = `
import * as element from '@wordpress/element';
window.wp = { element };
`;

/** WordPress 7.1's list-mode bulk row, reduced to the parts the slot reads. */
const LIST_TABLENAV = `
<div class="tablenav top">
  <div class="alignleft actions bulkactions">
    <select id="bulk-action-selector-top"><option>Bulk actions</option></select>
    <input type="submit" id="doaction" class="button action" value="Apply">
  </div>
  <div class="tablenav-pages"><span class="displaying-num">41 items</span></div>
</div>`;

/** …and grid mode's, likewise. */
const GRID_TOOLBAR = `
<div class="media-toolbar wp-filter">
  <div class="media-toolbar-secondary">
    <select id="media-attachment-filters"></select>
    <select id="media-attachment-date-filters"></select>
    <button class="button media-button select-mode-toggle-button" type="button">Bulk select</button>
  </div>
</div>`;

const PAGE = `<!DOCTYPE html>
<html><head><meta charset="utf-8"></head>
<body>
<div id="wpbody-content">${LIST_TABLENAV}</div>
<div id="root"></div>
<script src="./wp-element.js"></script>
<script src="./app.js"></script>
</body></html>`;

async function main() {
    const dir = await mkdtemp(join(tmpdir(), 'folderfolio-slot-'));
    let browser;

    try {
        await writeFile(
            join(dir, 'app.tsx'),
            APP.replace('__SLOT__', join(SRC, 'lib/toolbar-slot'))
        );
        await writeFile(join(dir, 'harness.js'), HARNESS);
        await writeFile(join(dir, 'wp-element.js'), '');
        await writeFile(join(dir, 'index.html'), PAGE);

        await esbuild.build({
            absWorkingDir: ROOT,
            entryPoints: [join(dir, 'app.tsx')],
            outfile: join(dir, 'app.js'),
            bundle: true,
            format: 'iife',
            target: ['es2020'],
            alias: ALIAS,
            jsx: 'automatic',
            jsxDev: false,
            logLevel: 'warning',
            nodePaths: [join(ROOT, 'node_modules')],
        });

        await esbuild.build({
            absWorkingDir: ROOT,
            entryPoints: [join(dir, 'harness.js')],
            outfile: join(dir, 'wp-element.js'),
            bundle: true,
            format: 'iife',
            define: { 'process.env.NODE_ENV': '"production"' },
            logLevel: 'warning',
            allowOverwrite: true,
            nodePaths: [join(ROOT, 'node_modules')],
        });

        browser = await chromium.launch(
            process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}
        );

        const page = await browser.newPage();
        const problems = [];
        page.on('console', (m) => m.type() === 'error' && problems.push(m.text()));
        page.on('pageerror', (e) => problems.push(String(e)));

        await page.goto(pathToFileURL(join(dir, 'index.html')).href);
        await page.waitForSelector('#ff-button');

        // ---------------------------------------------------- where it lands
        assert.equal(
            await page.$eval('#ff-button', (el) => el.closest('.folderfolio-slot')?.parentElement?.className),
            'alignleft actions bulkactions',
            'the slot did not land in the bulk-actions group'
        );

        assert.equal(
            await page.$eval('.folderfolio-slot', (el) => el.nextElementSibling?.id),
            'doaction',
            'the slot is not before Apply — screen 06 puts Add to folder between Bulk actions and Apply'
        );

        // --------------------------------------------------- state to lose
        await page.click('#ff-button');
        await page.click('#ff-button');
        await page.fill('#ff-input', 'half-typed');

        assert.equal(await page.$eval('#ff-button', (el) => el.textContent), '1:2');

        const before = await page.evaluate(() => {
            window.__ffNode = document.getElementById('ff-button');

            return true;
        });

        assert.ok(before);

        // A tree mutation's worth of re-rendering, before anything moves.
        await page.evaluate(() => window.__ffRerender());
        await page.waitForTimeout(50);

        assert.ok(
            await page.evaluate(() => window.__ffNode === document.getElementById('ff-button')),
            're-rendering alone replaced the control — the slot element is being rebuilt per render'
        );

        // ----------------------------- the thing that used to destroy it all
        await page.focus('#ff-button');
        assert.equal(await page.evaluate(() => document.activeElement?.id), 'ff-button');

        // Exactly what lib/list-refresh.ts does: swap in the server's copy of
        // .tablenav.top, which knows nothing about FolderFolio.
        await page.evaluate((markup) => {
            const next = new DOMParser()
                .parseFromString(markup, 'text/html')
                .querySelector('.tablenav.top');

            document.querySelector('.tablenav.top').replaceWith(next);
        }, LIST_TABLENAV);

        await page.waitForFunction(
            () => document.querySelector('.tablenav.top .bulkactions .folderfolio-slot') !== null,
            undefined,
            { timeout: 2000 }
        );

        assert.equal(
            await page.$eval('#ff-button', (el) => el.textContent),
            '1:2',
            'the control remounted: a new instance, so an open flyout and an in-flight request would both have been thrown away'
        );

        assert.equal(
            await page.$eval('#ff-input', (el) => el.value),
            'half-typed',
            'the input lost what was typed in it, so the node was recreated rather than moved'
        );

        assert.ok(
            await page.evaluate(() => window.__ffNode === document.getElementById('ff-button')),
            'the button is a different DOM node than before the swap'
        );

        assert.equal(
            await page.evaluate(() => document.activeElement?.id),
            'ff-button',
            'focus was not restored after the toolbar was replaced — a keyboard user is returned to the top of the document by a refresh they did not ask for'
        );

        // ------------------------------------------ focus is not *stolen* back
        await page.evaluate(() => {
            const outside = document.createElement('button');
            outside.id = 'ff-outside';
            document.body.appendChild(outside);
            outside.focus();
        });

        await page.evaluate((markup) => {
            const next = new DOMParser()
                .parseFromString(markup, 'text/html')
                .querySelector('.tablenav.top');

            document.querySelector('.tablenav.top').replaceWith(next);
        }, LIST_TABLENAV);

        await page.waitForFunction(
            () => document.querySelector('.tablenav.top .bulkactions .folderfolio-slot') !== null
        );

        assert.equal(
            await page.evaluate(() => document.activeElement?.id),
            'ff-outside',
            'the slot pulled focus away from where the user had moved it'
        );

        // ------------------------------------------------------- grid markup
        await page.evaluate((markup) => {
            document.querySelector('.tablenav.top').remove();
            document.getElementById('wpbody-content').innerHTML = markup;
        }, GRID_TOOLBAR);

        await page.waitForFunction(
            () => document.querySelector('.media-toolbar-secondary .folderfolio-slot') !== null,
            undefined,
            { timeout: 2000 }
        );

        assert.equal(
            await page.$eval('.folderfolio-slot', (el) => el.nextElementSibling?.className),
            'button media-button select-mode-toggle-button',
            'in grid the slot goes after the date filter and before Bulk select — screen 03'
        );

        assert.equal(
            await page.$eval('#ff-button', (el) => el.textContent),
            '1:2',
            'moving between the two toolbars remounted the control'
        );

        assert.deepEqual(problems, [], `the page logged errors: ${problems.join(' | ')}`);

        console.log('toolbar slot: placement, survival, node identity, focus — all verified');
    } finally {
        await browser?.close();
    }
}

await main();
