/**
 * Proves the wp-element pipeline, in a real browser, without WordPress.
 *
 * The architecture plan called the React-through-wp.element aliasing "the one
 * real integration risk", because every way it can fail is quiet:
 *
 *   - an alias that does not apply ships a second React, and the app works
 *     perfectly until it renders inside a core tree;
 *   - an export @wordpress/element does not re-export arrives as `undefined`
 *     and throws on first use, not at load;
 *   - a jsx-runtime that mishandles `key` produces a list that renders
 *     correctly and reorders wrongly;
 *   - useSyncExternalStore missing takes out Zustand and TanStack Query
 *     together, i.e. the entire state layer, and only under a subscription.
 *
 * None of those are caught by a type-check or by the bundle building. So this
 * builds a fixture through the shipping alias table, puts it on a page whose
 * only React is `window.wp.element` — exactly as wp-admin does — and asserts
 * on the rendered DOM.
 *
 *   node tools/verify-pipeline.mjs
 */

import assert from 'node:assert/strict';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

import * as esbuild from 'esbuild';
// From @playwright/test rather than `playwright`, so this needs no dependency
// the e2e suite does not already have.
import { chromium } from '@playwright/test';

import { ALIAS, ROOT } from './esbuild.mjs';

/**
 * The fixture.
 *
 * Deliberately not a component we ship. This exercises the pipeline's seams
 * rather than any feature: JSX with an interpolated child and a keyed list,
 * a hook that re-renders, an external store subscribed through
 * useSyncExternalStore, a portal (which lives in react-dom, a different
 * alias), and createRoot (which lives in react-dom/client, a third).
 */
const APP = `
import { useState, useSyncExternalStore } from 'react';
import { createPortal } from 'react-dom';
import { createRoot } from 'react-dom/client';

// A hand-rolled store with exactly the shape Zustand and TanStack Query both
// use: subscribe + getSnapshot, read through useSyncExternalStore.
let folders = ['Brand', 'Archive'];
const listeners = new Set();
const store = {
    subscribe(fn) {
        listeners.add(fn);
        return () => listeners.delete(fn);
    },
    get() {
        return folders;
    },
    add(name) {
        folders = [...folders, name];
        listeners.forEach((fn) => fn());
    },
    reverse() {
        folders = [...folders].reverse();
        listeners.forEach((fn) => fn());
    },
};

function Tree() {
    const names = useSyncExternalStore(store.subscribe, store.get);
    const [selected, setSelected] = useState('Brand');

    return (
        <>
            <ul id="tree">
                {names.map((name, depth) => (
                    <li
                        key={name}
                        data-depth={depth}
                        aria-selected={name === selected}
                        onClick={() => setSelected(name)}
                    >
                        {name}
                    </li>
                ))}
            </ul>
            <p id="selected">selected: {selected}</p>
            {createPortal(<span id="portal">portalled</span>, document.body)}
        </>
    );
}

createRoot(document.getElementById('root')).render(<Tree />);

// Handles for the assertions below.
window.__ff = { store, reactOnPage: window.React };
`;

/**
 * The page's only React, put there the way WordPress puts it there: a global,
 * loaded by a separate script tag, before ours.
 *
 * Nothing in this file writes window.React — so if the fixture bundle turns
 * out to contain its own React, the count of reconcilers on the page is two
 * and the DOM assertions would still pass. That is what the
 * `bundle carries no React` assertion is for.
 */
const HARNESS = `
import * as element from '@wordpress/element';
window.wp = { element };
`;

const PAGE = `<!DOCTYPE html>
<html><head><meta charset="utf-8"></head>
<body><div id="root"></div>
<script src="./wp-element.js"></script>
<script src="./app.js"></script>
</body></html>`;

async function main() {
    const dir = await mkdtemp(join(tmpdir(), 'folderfolio-pipeline-'));
    let browser;

    try {
        await writeFile(join(dir, 'app.tsx'), APP);
        await writeFile(join(dir, 'wp-element.js'), '');
        await writeFile(join(dir, 'harness.js'), HARNESS);
        await writeFile(join(dir, 'index.html'), PAGE);

        // The fixture, through the shipping alias table and the shipping JSX
        // settings. Minified, because that is what ships and because a
        // minifier is one more thing that can quietly drop a `key`.
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
            minify: true,
            logLevel: 'warning',
            // The fixture is written to a temp directory, so esbuild would
            // walk up from /tmp looking for node_modules and find nothing.
            nodePaths: [join(ROOT, 'node_modules')],
        });

        // WordPress's React, standing in for the wp-element handle.
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

        const bundle = await import('node:fs/promises').then((fs) =>
            fs.readFile(join(dir, 'app.js'), 'utf8')
        );

        // A bundled React leaves its own fingerprints. If any of these are in
        // our output, the alias did not take and the page has two reconcilers.
        for (const fingerprint of [
            'react-dom.production',
            'Minified React error',
            'react.development',
        ]) {
            assert.ok(
                !bundle.includes(fingerprint),
                `the app bundle contains "${fingerprint}" — React was bundled instead of aliased onto wp.element`
            );
        }

        assert.ok(
            bundle.includes('wp.element is not on the page'),
            'the shim is not in the bundle, so the alias did not apply at all'
        );

        // CHROMIUM_PATH is for images that ship their own Chromium rather than
        // the build Playwright downloads — some CI runners, and the container
        // this was first written in. Unset everywhere else.
        browser = await chromium.launch(
            process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}
        );
        const page = await browser.newPage();

        const problems = [];
        page.on('console', (m) => {
            if (m.type() === 'error') {
                problems.push(m.text());
            }
        });
        page.on('pageerror', (e) => problems.push(String(e)));

        await page.goto(pathToFileURL(join(dir, 'index.html')).href);
        await page.waitForSelector('#tree li');

        assert.deepEqual(problems, [], `the page logged errors: ${problems.join(' | ')}`);

        // JSX: an interpolated child, and a keyed list with a per-item attribute.
        assert.deepEqual(
            await page.$$eval('#tree li', (els) =>
                els.map((el) => [el.textContent, el.dataset.depth, el.getAttribute('aria-selected')])
            ),
            [
                ['Brand', '0', 'true'],
                ['Archive', '1', 'false'],
            ],
            'JSX did not render the keyed list as written'
        );

        // react-dom's createPortal, through a second alias.
        assert.equal(
            await page.$eval('#portal', (el) => el.parentElement.id),
            '',
            'createPortal did not mount onto document.body'
        );

        // useState, and a re-render driven by an event.
        await page.click('#tree li:nth-child(2)');
        await page.waitForFunction(
            () => document.querySelector('#selected').textContent === 'selected: Archive'
        );

        // useSyncExternalStore: the store layer's only contract with React.
        await page.evaluate(() => window.__ff.store.add('Client Work'));
        await page.waitForFunction(() => document.querySelectorAll('#tree li').length === 3);

        assert.equal(
            await page.$eval('#tree li:nth-child(3)', (el) => el.textContent),
            'Client Work',
            'useSyncExternalStore did not deliver the store update'
        );

        // Keyed reconciliation, which is the only assertion that can tell
        // whether `key` reached React at all.
        //
        // An attribute is stamped onto the DOM node currently showing "Brand"
        // — React does not know about it, so it travels with the node and not
        // with the data. Reversing the list then has exactly two possible
        // outcomes: React matched by key and moved that same node to the end,
        // or it matched by position and re-used the node in place for
        // different content. The first keeps the marked node reading "Brand";
        // the second makes it read "Client Work".
        //
        // Dropping the key does not throw, does not warn in a production
        // build, and renders a correct-looking list. It only shows up here.
        await page.evaluate(() => {
            const brand = [...document.querySelectorAll('#tree li')].find(
                (el) => el.textContent === 'Brand'
            );
            brand.dataset.probe = 'yes';
        });

        await page.evaluate(() => window.__ff.store.reverse());
        await page.waitForFunction(
            () => document.querySelector('#tree li').textContent === 'Client Work'
        );

        const probed = await page.$$eval('#tree li', (els) =>
            els.map((el, i) => [i, el.textContent, el.dataset.probe ?? null])
        );

        assert.deepEqual(
            probed,
            [
                [0, 'Client Work', null],
                [1, 'Archive', null],
                [2, 'Brand', 'yes'],
            ],
            'the list reconciled by position, not by key — `key` never reached React. '
                + `Got ${JSON.stringify(probed)}`
        );

        console.log('wp-element pipeline: ok');
        console.log(`  app bundle ${(bundle.length / 1024).toFixed(1)}KB, no React inside`);
    } finally {
        await browser?.close();
        await rm(dir, { recursive: true, force: true });
    }
}

main().catch((error) => {
    console.error('wp-element pipeline: FAILED');
    console.error(error.message ?? error);
    process.exit(1);
});
