import { defineConfig, devices } from '@playwright/test';

/**
 * End-to-end against a real WordPress, booted by the test run itself.
 *
 * WordPress Playground runs WordPress on php-wasm inside Node, so the suite
 * needs no Docker, no database and no site to point at. `globalSetup` boots
 * one, mounts the staged plugin into it, logs in, waits until it is actually
 * serving, and shuts it down afterwards. That is what makes these tests
 * runnable in CI and on a laptop with nothing installed, which the previous
 * suite — which assumed a WordPress at localhost:8889 — was not.
 *
 * The boot is ours rather than Playwright's `webServer` block for a specific
 * reason, written up in tests/e2e/global-setup.ts: Playground accepts
 * connections half a second in and answers 502 for the next fifteen seconds,
 * which neither of Playwright's readiness mechanisms handles — and its failure
 * mode was a bare "Timed out waiting 180000ms from config.webServer" in CI.
 *
 * WP_BASE_URL overrides the whole thing, for running against a real install.
 */
const port = Number(process.env.WP_PORT || 9411);
const baseURL = process.env.WP_BASE_URL || `http://127.0.0.1:${port}`;

export default defineConfig({
  testDir: './tests/e2e',
  globalSetup: './tests/e2e/global-setup.ts',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  // One worker: every test shares one WordPress, and folders are global state.
  workers: 1,
  reporter: process.env.CI
    ? [['github'], ['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]]
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
});
