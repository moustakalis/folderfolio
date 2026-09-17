import { defineConfig, devices } from '@playwright/test';

/**
 * End-to-end against a real WordPress, booted by the test run itself.
 *
 * WordPress Playground runs WordPress on php-wasm inside Node, so the suite
 * needs no Docker, no database and no site to point at: `webServer` below
 * boots one, mounts this checkout into it as a plugin, logs in, and tears it
 * down afterwards. That is what makes these tests runnable in CI and on a
 * laptop with nothing installed, which the previous suite — which assumed a
 * WordPress at localhost:8889 — was not.
 *
 * WP_BASE_URL still overrides it, for running against a real install.
 */
const port = Number(process.env.WP_PORT || 9411);
const external = process.env.WP_BASE_URL;
const baseURL = external || `http://127.0.0.1:${port}`;

/** The checkout is the plugin: folderfolio.php sits at its root. */
const pluginDir = process.env.WP_PLUGIN_DIR || process.cwd();

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  // One worker: every test shares one WordPress, and folders are global state.
  workers: 1,
  reporter: process.env.CI
    ? [['github'], ['html', { outputFolder: 'playwright-report', open: 'never' }]]
    : 'list',
  use: {
    baseURL,
    // Images that ship their own Chromium rather than the build Playwright
    // downloads — some CI runners, and the container this was written in.
    // Unset everywhere else, where Playwright's own browser is used.
    ...(process.env.CHROMIUM_PATH
      ? { launchOptions: { executablePath: process.env.CHROMIUM_PATH } }
      : {}),
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        // Spread after the device, or the device's own browser settings win
        // and the override is silently ignored.
        ...(process.env.CHROMIUM_PATH
          ? { channel: undefined, launchOptions: { executablePath: process.env.CHROMIUM_PATH } }
          : {}),
      },
    },
  ],
  webServer: external
    ? undefined
    : {
        command: [
          'npx wp-playground-cli server',
          `--port ${port}`,
          `--auto-mount "${pluginDir}"`,
          '--login',
          '--verbosity quiet',
        ].join(' '),
        url: `${baseURL}/wp-admin/upload.php`,
        // Cold, Playground downloads WordPress; warm it is a few seconds.
        timeout: 180_000,
        reuseExistingServer: !process.env.CI,
        stdout: 'ignore',
        stderr: 'pipe',
      },
});
