import { execFileSync, spawn, type ChildProcess } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { get } from 'node:http';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

/**
 * Boot the WordPress the suite runs against, and wait until it means it.
 *
 * This used to be Playwright's own `webServer` block, and it never worked
 * against WordPress Playground in any environment it could be tested in —
 * locally or in CI, the run sat there until "Timed out waiting 180000ms from
 * config.webServer", which says nothing about why.
 *
 * What it was hiding: **Playground accepts connections about half a second
 * after it starts and answers 502 for the next fifteen seconds** while it
 * downloads WordPress and installs it. Measured, not guessed: TCP accept at
 * 510ms, first HTTP response 502 at 560ms, a real 302 some seconds later. So
 * neither of Playwright's readiness mechanisms fits — waiting on the port is
 * satisfied in half a second by a server that cannot serve a page, and its URL
 * probe never resolved against this server at all.
 *
 * Fifteen lines of polling that this project controls is worth more than a
 * built-in whose failure mode is a timeout with no explanation. It also gives
 * the failure a message: when the boot does fail, what the CLI printed is in
 * the error.
 */

const PORT = Number(process.env.WP_PORT || 9411);
const BASE = `http://127.0.0.1:${PORT}`;

/** Cold, Playground downloads WordPress and unpacks it inside php-wasm. */
const BOOT_TIMEOUT = 600_000;

const root = resolve(__dirname, '../..');

/**
 * What gets mounted: the staged plugin, not the checkout.
 *
 * `--auto-mount` copies the directory it is given into the VFS, and the
 * checkout is ~700MB of node_modules, vendor and .git that WordPress has no
 * use for. The staged plugin is a few megabytes and is the same allowlist
 * bin/build-zip.sh ships, so a packaging mistake fails a test here rather than
 * reaching a download.
 */
function stagePlugin(): string {
  if (process.env.WP_PLUGIN_DIR) {
    return process.env.WP_PLUGIN_DIR;
  }

  // The suite asserts on rendered React and on styles. Without a build it
  // would fail in twenty places, none of which would say why.
  if (!existsSync(join(root, 'assets/build/core/admin.css'))) {
    throw new Error('assets/build is missing — run `yarn build` before `yarn test:e2e`.');
  }

  const staged = join(root, 'var/e2e-plugin/folderfolio');

  execFileSync('bash', [join(root, 'bin/stage-plugin.sh'), staged], {
    cwd: root,
    stdio: 'inherit',
  });

  return staged;
}

/**
 * The Blueprint that gives Playground the media library the suite expects.
 *
 * The container's rig imports four PNGs in tests/e2e/rig/setup.sh; Playground
 * boots with an empty library, and every spec that files, orders or zips a
 * file failed in CI for want of one (24 Sep). The seed is a PHP file so it
 * can be read and linted as PHP; the Blueprint is written at boot around it.
 */
function mediaBlueprint(): string {
  const code = readFileSync(join(root, 'tests/e2e/playground/seed-media.php'), 'utf8');
  const path = join(mkdtempSync(join(tmpdir(), 'folderfolio-e2e-')), 'blueprint.json');

  // `login` as well: a Blueprint replaces the CLI's own steps, and with one
  // passed, `--login` alone left every page on wp-login.php (found running
  // this in the container, 24 Sep).
  writeFileSync(path, JSON.stringify({ login: true, steps: [{ step: 'runPHP', code }] }));

  return path;
}

/**
 * One request, answered or not.
 *
 * A 502 is Playground still booting; a 200 or a 302 is WordPress. Anything
 * else — including a connection refused — is "not yet".
 */
function probe(): Promise<number | null> {
  return new Promise((done) => {
    const request = get(`${BASE}/wp-admin/upload.php`, (response) => {
      response.resume();
      response.on('end', () => done(response.statusCode ?? null));
    });

    request.setTimeout(5_000, () => {
      request.destroy();
      done(null);
    });

    request.on('error', () => done(null));
  });
}

async function waitForWordPress(server: ChildProcess, output: () => string): Promise<void> {
  const deadline = Date.now() + BOOT_TIMEOUT;

  while (Date.now() < deadline) {
    if (server.exitCode !== null) {
      throw new Error(
        `WordPress Playground exited with code ${server.exitCode} before it was ready.\n\n${output()}`
      );
    }

    const status = await probe();

    if (status === 200 || status === 302) {
      return;
    }

    await new Promise((wait) => setTimeout(wait, 500));
  }

  throw new Error(
    `WordPress Playground was not serving ${BASE} after ${BOOT_TIMEOUT / 1000}s.\n\n${output()}`
  );
}

export default async function globalSetup(): Promise<(() => Promise<void>) | void> {
  // An external site: the suite is pointed at a real WordPress and boots
  // nothing.
  if (process.env.WP_BASE_URL) {
    return;
  }

  const bin = join(root, 'node_modules/.bin/wp-playground-cli');

  if (!existsSync(bin)) {
    throw new Error(
      'wp-playground-cli is not installed — run `yarn install` before `yarn test:e2e`.'
    );
  }

  const server = spawn(
    bin,
    [
      'server',
      '--port',
      String(PORT),
      '--auto-mount',
      stagePlugin(),
      '--login',
      '--blueprint',
      mediaBlueprint(),
      '--verbosity',
      'quiet',
    ],
    {
      cwd: root,
      // Its own process group, so the whole tree goes at teardown: the CLI
      // spawns worker threads and a stray one holds the port against the next
      // run.
      detached: true,
      stdio: ['ignore', 'pipe', 'pipe'],
    }
  );

  let log = '';

  const collect = (chunk: Buffer): void => {
    // Kept, not printed. It is noise on a run that works and the only useful
    // thing on a run that does not.
    log += chunk.toString();
  };

  server.stdout?.on('data', collect);
  server.stderr?.on('data', collect);

  try {
    await waitForWordPress(server, () => log.trim() || '(the server printed nothing)');
  } catch (error) {
    stop(server);

    throw error;
  }

  return async () => {
    stop(server);
  };
}

function stop(server: ChildProcess): void {
  if (server.pid === undefined || server.exitCode !== null) {
    return;
  }

  try {
    process.kill(-server.pid, 'SIGTERM');
  } catch {
    // Already gone, or never had a group of its own.
    server.kill('SIGTERM');
  }
}
