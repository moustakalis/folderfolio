/**
 * Unit tests for the rail's pure decision functions, under `node --test`.
 *
 *   node tools/test-js.mjs            every tests/js/**\/*.test.ts
 *   node tools/test-js.mjs paste      only files whose path contains "paste"
 *   npx tsc --noEmit -p tests/js      type-check the tests (esbuild only strips)
 *
 * ## Why this and not Jest or Vitest
 *
 * The functions worth testing on their own — `planPaste`, `planSiblingMove`,
 * `sortTree` — are the ones whose answer decides both what a menu row shows
 * and which request is sent. They are plain functions over plain trees. What
 * they need is TypeScript stripped and their imports resolved, which esbuild
 * (already the build) does in milliseconds, and an assertion library and a
 * runner, which Node has had since 18. A test framework would be a fourth
 * toolchain for three files.
 *
 * ## How the modules load outside the browser
 *
 * Each test is bundled on its own. Our sources are bundled in; packages stay
 * external and are resolved by Node from node_modules — so `react` is the real
 * React that @wordpress/element depends on, **not** `shims/wp-element.ts`,
 * which throws at load when there is no `wp.element` on the page. A banner
 * gives the modules the one global they read at load time: `store.ts` asks
 * `window.location` which folder the URL names. Everything else they read
 * (`window.folderFolio`) is read at call time, and a test sets it when the
 * answer depends on it.
 *
 * The bundles go to var/js-tests/ inside the checkout, so that Node finds
 * node_modules from there. var/ is gitignored.
 */

import { spawnSync } from 'node:child_process';
import { mkdir, readdir, rm } from 'node:fs/promises';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import * as esbuild from 'esbuild';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const TESTS = join(ROOT, 'tests/js');
const OUT = join(ROOT, 'var/js-tests');

const BANNER = `
globalThis.window ??= {
    location: new URL('http://localhost/wp-admin/upload.php'),
    folderFolio: {},
};
`;

async function find(dir) {
    const found = [];

    for (const entry of await readdir(dir, { withFileTypes: true })) {
        const path = join(dir, entry.name);

        if (entry.isDirectory()) {
            found.push(...(await find(path)));
        } else if (entry.name.endsWith('.test.ts')) {
            found.push(path);
        }
    }

    return found;
}

const filter = process.argv[2];
const files = (await find(TESTS)).filter((file) => !filter || file.includes(filter)).sort();

if (files.length === 0) {
    console.error(`No tests in ${relative(ROOT, TESTS)}${filter ? ` matching "${filter}"` : ''}.`);
    process.exit(1);
}

await rm(OUT, { recursive: true, force: true });
await mkdir(OUT, { recursive: true });

const outputs = [];

for (const file of files) {
    const outfile = join(OUT, relative(TESTS, file).replace(/\.ts$/, '.mjs'));

    await esbuild.build({
        entryPoints: [file],
        outfile,
        bundle: true,
        platform: 'node',
        format: 'esm',
        target: 'node18',
        packages: 'external',
        banner: { js: BANNER },
        sourcemap: 'inline',
        logLevel: 'warning',
    });

    outputs.push(outfile);
}

const run = spawnSync(process.execPath, ['--enable-source-maps', '--test', '--test-reporter=spec', ...outputs], {
    cwd: ROOT,
    stdio: 'inherit',
});

process.exit(run.status ?? 1);
