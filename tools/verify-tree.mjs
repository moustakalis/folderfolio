/**
 * The tree always has exactly one way in from the keyboard.
 *
 * The tree is a single tab stop: exactly one row carries `tabindex="0"` and
 * the arrow keys move which one. Until step 10 that invariant was structural —
 * one component computed it for every row at once, so it could not be wrong.
 *
 * It is not structural any more. Rows decide for themselves, because having
 * the tree own focus meant one arrow key re-rendered every row in it (126ms
 * per keystroke at 5,000 rows, measured — see tools/measure-tree.mjs). Now it
 * is an emergent property of three rules:
 *
 *   - the focused row is tabbable;
 *   - the first row is tabbable when nothing is focused;
 *   - focus is never left pointing at a row that is not on screen.
 *
 * Break any one and the whole tree drops out of the tab order, silently, for
 * keyboard users only. Nothing on screen changes. This asserts it across every
 * way a focused row can stop existing.
 *
 *   node tools/verify-tree.mjs
 */

import assert from 'node:assert/strict';
import { mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

import * as esbuild from 'esbuild';
import { chromium } from '@playwright/test';

import { ALIAS, ROOT, SRC } from './esbuild.mjs';

const APP = `
import { flushSync } from 'react-dom';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { Tree } from '__SRC__/apps/rail/Tree';
import { useRail } from '__SRC__/apps/rail/store';

const node = (id, name, children = []) => ({
    id, parent_id: null, name, color: null, depth: 0, path: '/' + id + '/',
    children, count: 0, total_count: 0,
});

/**
 *   Archive            <- first row, and never the one being collapsed
 *   Brand
 *     Logos
 *       Primary
 *   Campaigns
 *
 * Archive exists so that "the tab stop went to the row you collapsed" and
 * "the tab stop fell back to the top of the tree" are different answers. With
 * Brand first they are the same row, and the assertion below cannot tell a
 * working collapse from a focus that was simply dropped.
 */
const TREE = [
    node(5, 'Archive'),
    node(1, 'Brand', [node(2, 'Logos', [node(3, 'Primary')])]),
    node(4, 'Campaigns'),
];

const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
const noop = () => {};

const root = createRoot(document.getElementById('root'));

window.__render = (nodes) => {
    flushSync(() => {
        root.render(
            <QueryClientProvider client={client}>
                <Tree nodes={nodes} loading={false} onSaveEdit={noop} onCancelEdit={noop} onDelete={noop} />
            </QueryClientProvider>
        );
    });
};

window.__set = (patch) => flushSync(() => useRail.setState(patch));
window.__state = () => useRail.getState();
window.__TREE = TREE;

// The subtree with folder 3 removed — a delete, or a refetch that lost it.
window.__PRUNED = [node(5, 'Archive'), node(1, 'Brand', [node(2, 'Logos')]), node(4, 'Campaigns')];

window.__tabbable = () =>
    [...document.querySelectorAll('.folderfolio-row')]
        .filter((el) => el.getAttribute('tabindex') === '0')
        .map((el) => el.textContent.trim());

window.__ready = true;
`;

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
    const dir = await mkdtemp(join(tmpdir(), 'folderfolio-tree-'));
    let browser;

    try {
        await writeFile(join(dir, 'app.tsx'), APP.replaceAll('__SRC__', SRC));
        await writeFile(join(dir, 'harness.js'), HARNESS);
        await writeFile(join(dir, 'wp-element.js'), '');
        await writeFile(join(dir, 'index.html'), PAGE);

        await esbuild.build({
            absWorkingDir: ROOT,
            entryPoints: [join(dir, 'app.tsx')],
            outfile: join(dir, 'app.js'),
            bundle: true, format: 'iife', target: ['es2020'],
            alias: ALIAS, jsx: 'automatic', jsxDev: false,
            logLevel: 'warning', nodePaths: [join(ROOT, 'node_modules')],
        });

        await esbuild.build({
            absWorkingDir: ROOT,
            entryPoints: [join(dir, 'harness.js')],
            outfile: join(dir, 'wp-element.js'),
            bundle: true, format: 'iife',
            define: { 'process.env.NODE_ENV': '"production"' },
            logLevel: 'warning', allowOverwrite: true,
            nodePaths: [join(ROOT, 'node_modules')],
        });

        browser = await chromium.launch(
            process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}
        );
        const page = await browser.newPage();
        const problems = [];
        page.on('pageerror', (e) => problems.push(String(e)));
        await page.goto(pathToFileURL(join(dir, 'index.html')).href);
        await page.waitForFunction(() => window.__ready);

        const tabbable = () => page.evaluate(() => window.__tabbable());

        const one = async (what, expected) => {
            const rows = await tabbable();

            assert.equal(
                rows.length,
                1,
                `${what}: ${rows.length} rows carry tabindex="0" — the tree is `
                    + (rows.length === 0 ? 'unreachable by Tab' : 'a keyboard trap')
                    + ` (${JSON.stringify(rows)})`
            );

            if (expected) {
                assert.ok(
                    rows[0].startsWith(expected),
                    `${what}: expected the tab stop on "${expected}", found "${rows[0]}"`
                );
            }
        };

        // ------------------------------------------------- nothing focused yet
        await page.evaluate(() => {
            window.__set({ focusedId: null, selectedId: null, expandedIds: new Set() });
            window.__render(window.__TREE);
        });
        await one('cold, nothing focused', 'Archive');

        // ------------------------------------------------------ focus moves
        await page.evaluate(() => window.__set({ focusedId: 4 }));
        await one('focus moved to a root row', 'Campaigns');

        // ---------------------------------------- focus on a row three deep
        await page.evaluate(() => {
            window.__set({ expandedIds: new Set([1, 2]), focusedId: 3 });
        });
        await one('focus deep in an expanded branch', 'Primary');

        // --------------------- the branch holding the focused row collapses
        //
        // Through the row's own switcher, which is the path a user takes.
        await page.evaluate(() => {
            const rows = [...document.querySelectorAll('.folderfolio-row')];
            const brand = rows.find((r) => r.textContent.trim().startsWith('Brand'));
            brand.querySelector('.folderfolio-row__switcher').click();
        });
        // Brand, not Archive: collapsing takes focus to the row it collapsed,
        // rather than dropping it and letting the first row stand in.
        await one('after collapsing the branch the focused row was in', 'Brand');

        // ------------------------------- the focused row is deleted under us
        await page.evaluate(() => {
            window.__set({ expandedIds: new Set([1, 2]), focusedId: 3 });
            window.__render(window.__PRUNED);
        });
        await one('after the focused folder disappeared from the tree', 'Archive');

        assert.deepEqual(
            await page.evaluate(() => window.__state().focusedId),
            null,
            'a focus id pointing at a folder that no longer exists was left in the store'
        );

        // ------------------------------------------ and it recovers from that
        await page.evaluate(() => window.__set({ focusedId: 4 }));
        await one('focus set again after a prune', 'Campaigns');

        assert.deepEqual(problems, [], `the page logged errors: ${problems.join(' | ')}`);

        console.log('tree: exactly one row is tabbable, in every state that can strand focus');
    } finally {
        await browser?.close();
    }
}

await main();
