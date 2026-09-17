/**
 * How big can the folder tree get before it stops feeling instant?
 *
 * The architecture plan asks for virtualisation. Virtualisation is not free
 * here: the tree is a nested `ul` / `role="group"` ARIA tree, and a windowed
 * one has to become flat `treeitem`s carrying `aria-setsize` and
 * `aria-posinset`. That is a restructure of the most carefully built and most
 * tested part of the UI, so it should not happen on a guess about a number
 * nobody has measured.
 *
 * This measures the real `Tree` component, through the real alias table, in a
 * real browser, at four tree sizes. Three numbers per size:
 *
 *   mount   — first render of the whole tree
 *   expand  — every folder opened at once, which is the worst case a user can
 *             actually reach (the `*` key opens a level; a deep link expands a
 *             path)
 *   arrow   — one press of Down. This is the one that decides the answer:
 *             focus is React state, so every row re-renders on every keystroke,
 *             and a tree that mounts in 300ms but takes 300ms per arrow key is
 *             unusable in a way that a mount time does not reveal.
 *
 *   node tools/measure-tree.mjs
 */

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
import { FolderSelect } from '__SRC__/apps/rail/FolderSelect';
import { AddToFolder } from '__SRC__/apps/rail/AddToFolder';
import { useRail } from '__SRC__/apps/rail/store';

/** A tree of \`total\` folders, four children per parent — a real filing shape. */
function build(total) {
    const nodes = [];
    const flat = [];
    let id = 0;

    const make = (depth, into) => {
        while (flat.length < total && into.length < 4) {
            const node = {
                id: ++id,
                parent_id: null,
                name: 'Folder ' + id,
                color: null,
                depth,
                path: '/' + id + '/',
                children: [],
                count: id % 7,
                total_count: id % 23,
            };
            into.push(node);
            flat.push(node);
        }
    };

    make(0, nodes);

    // Breadth-first, so the tree is wide and deep rather than a 10,000-long
    // chain, which is not a shape anyone's media library has.
    for (let i = 0; i < flat.length && flat.length < total; i++) {
        make(flat[i].depth + 1, flat[i].children);
    }

    return { nodes, ids: flat.map((n) => n.id) };
}

const mount = document.getElementById('root');
const root = createRoot(mount);

const noop = () => {};

// Every row is a drop target, and a drop target holds a mutation, so the tree
// cannot render outside a provider. Measuring it without one would be
// measuring a different component.
const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
const Wrap = ({ nodes }) => (
    <QueryClientProvider client={client}>
        <Tree nodes={nodes} loading={false} onSaveEdit={noop} onCancelEdit={noop} onDelete={noop} />
    </QueryClientProvider>
);

window.__measure = (total) => {
    const { nodes, ids } = build(total);
    const out = { total, built: ids.length };

    useRail.setState({ expandedIds: new Set(), focusedId: null, selectedId: null, query: '' });

    let t = performance.now();
    flushSync(() => {
        root.render(<Wrap nodes={nodes} />);
    });
    out.mount = +(performance.now() - t).toFixed(1);
    out.rowsAtMount = document.querySelectorAll('.folderfolio-row').length;

    t = performance.now();
    flushSync(() => {
        useRail.setState({ expandedIds: new Set(ids) });
    });
    out.expand = +(performance.now() - t).toFixed(1);
    out.rowsExpanded = document.querySelectorAll('.folderfolio-row').length;
    out.domNodes = document.getElementById('root').getElementsByTagName('*').length;

    // One arrow key: focus moves, every row re-renders.
    // Flushed, or React folds it into the measured update below and the
    // transition becomes null -> id, which is a change the tree does subscribe
    // to. That is not the arrow key; it is the first arrow key ever pressed.
    flushSync(() => { useRail.setState({ focusedId: ids[0] }); });
    window.__rowRenders = 0; window.__treeRenders = 0;
    t = performance.now();
    flushSync(() => {
        useRail.setState({ focusedId: ids[1] });
    });
    out.arrow = +(performance.now() - t).toFixed(1);
    out.rowRendersPerArrow = window.__rowRenders;
    out.treeRendersPerArrow = window.__treeRenders;

    return out;
};

/* ------------------------------------------------------------------ */
/* The two surfaces that render EVERY folder, flat, with no collapsing. */
/* The tree only renders expanded branches; these do not have that      */
/* escape, so they are the ones a 1,000-folder library actually meets.  */
/* ------------------------------------------------------------------ */

const selectRoot = createRoot(document.getElementById('select-root'));
const bulkRoot = createRoot(document.getElementById('bulk-root'));

const settle = (ms) => new Promise((r) => window.setTimeout(r, ms));
const painted = () =>
    new Promise((r) => window.requestAnimationFrame(() => window.requestAnimationFrame(r)));

/** Wait for a condition, or give up — so a flaky mount fails loudly. */
async function until(fn, ms = 2000) {
    const stop = performance.now() + ms;
    while (performance.now() < stop) {
        if (fn()) return true;
        await settle(16);
    }
    return false;
}

window.__measureSelect = async (total) => {
    const { nodes } = build(total);

    flushSync(() => selectRoot.render(null));
    await settle(50);

    const t = performance.now();
    flushSync(() => {
        selectRoot.render(
            <QueryClientProvider client={client}>
                <FolderSelect nodes={nodes} />
            </QueryClientProvider>
        );
    });
    const elapsed = performance.now() - t;
    await painted();
    const out = {
        total,
        select: +elapsed.toFixed(1),
        options: document.querySelectorAll('#select-root option').length,
    };

    flushSync(() => selectRoot.render(null));
    await settle(50);

    return out;
};

window.__measureFlyout = async (total) => {
    const { nodes } = build(total);

    flushSync(() => bulkRoot.render(null));
    await settle(80);

    flushSync(() => {
        bulkRoot.render(
            <QueryClientProvider client={client}>
                <AddToFolder nodes={nodes} />
            </QueryClientProvider>
        );
    });

    const ready = await until(() => {
        const b = document.querySelector('.folderfolio-bulk');
        return b && !b.disabled;
    });

    if (!ready) {
        return { total, error: 'trigger never enabled' };
    }

    const trigger = document.querySelector('.folderfolio-bulk');

    let t = performance.now();
    trigger.click();
    await painted();
    const open = +(performance.now() - t).toFixed(1);
    const rows = document.querySelectorAll('.folderfolio-flyout__row').length;

    const field = document.querySelector('.folderfolio-flyout__input');

    if (!field) {
        return { total, error: 'flyout did not open' };
    }

    const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
    t = performance.now();
    setter.call(field, 'Folder 1');
    field.dispatchEvent(new Event('input', { bubbles: true }));
    await painted();
    const keystroke = +(performance.now() - t).toFixed(1);

    flushSync(() => bulkRoot.render(null));
    await settle(50);

    return { total, open, rows, keystroke };
};

window.__ready = true;
`;

const HARNESS = `
import * as element from '@wordpress/element';
window.wp = { element };
`;

const PAGE = `<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
  /* The real row geometry, so layout cost is real too. */
  .folderfolio-row { display:flex; align-items:center; gap:6px; height:36px;
    padding-left: calc(12px + 24px * var(--ff-depth, 0)); padding-right:10px; }
  .folderfolio-row__name { flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .folderfolio-tree, .folderfolio-tree ul { margin:0; padding:0; list-style:none; }
</style></head>
<body>
<div class="tablenav top"><div class="alignleft actions bulkactions">
  <input type="submit" id="doaction" value="Apply">
</div></div>
<ul id="fake-library"><li class="attachment selected" data-id="1"></li><li class="attachment selected" data-id="2"></li></ul>
<div id="select-root"></div>
<div id="bulk-root"></div>
<div id="root"></div>
<script src="./wp-element.js"></script>
<script src="./app.js"></script>
</body></html>`;

const SIZES = [200, 1000, 5000, 20000];

async function main() {
    const dir = process.env.KEEP_DIR || await mkdtemp(join(tmpdir(), 'folderfolio-measure-'));
    console.error('build dir:', dir);
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
            minify: true, logLevel: 'warning',
            nodePaths: [join(ROOT, 'node_modules')],
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
        page.on('pageerror', (e) => { throw e; });
        await page.goto(pathToFileURL(join(dir, 'index.html')).href);
        await page.waitForFunction(() => window.__ready);

        const rows = [];

        for (const size of SIZES) {
            // Three runs, best of — the first pays for JIT warm-up that a real
            // page has already paid elsewhere.
            let best = null;
            for (let i = 0; i < 3; i++) {
                const r = await page.evaluate((n) => window.__measure(n), size);
                if (!best || r.arrow < best.arrow) best = r;
            }
            rows.push(best);
        }

        const flat = [];
        for (const size of SIZES) {
            let best = null;
            for (let i = 0; i < 3; i++) {
                const sel = await page.evaluate((n) => window.__measureSelect(n), size);
                const fly = await page.evaluate((n) => window.__measureFlyout(n), size);
                const r = { ...sel, ...fly };
                if (r.error) throw new Error(`size ${size}: ${r.error}`);
                if (!best || r.open < best.open) best = r;
            }
            flat.push(best);
        }

        console.log('\nfolders   rows   DOM nodes   mount     expand    arrow key');
        console.log('-------   ----   ---------   -------   -------   ---------');
        for (const r of rows) {
            console.log(
                String(r.built).padEnd(9),
                String(r.rowsExpanded).padEnd(6),
                String(r.domNodes).padEnd(11),
                (r.mount + 'ms').padEnd(9),
                (r.expand + 'ms').padEnd(9),
                (r.arrow + 'ms').padEnd(10),
                'rows: ' + r.rowRendersPerArrow + '  tree: ' + r.treeRendersPerArrow
            );
        }
        console.log('\nThe two surfaces that render every folder, flat:\n');
        console.log('folders   <select> mount   options   flyout open   flyout keystroke');
        console.log('-------   -------------   -------   -----------   ----------------');
        for (const r of flat) {
            console.log(
                String(r.total).padEnd(9),
                (r.select + 'ms').padEnd(15),
                String(r.options).padEnd(9),
                (r.open + 'ms').padEnd(13),
                r.keystroke + 'ms'
            );
        }
        console.log('');
    } finally {
        await browser?.close();
    }
}

await main();
