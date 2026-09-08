import { expect, test } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth';

test.describe('FolderFolio Media Library', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/wp-admin/upload.php?mode=grid');
  });

  test('renders the FolderFolio folder tree', async ({ page }) => {
    await expect(page.locator('#folderfolio-sidebar')).toBeVisible();
    await expect(page.locator('#folderfolio-folder-tree')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Folders' })).toBeVisible();
  });

  test('creates a root folder', async ({ page }) => {
    page.on('dialog', async (dialog) => {
      expect(dialog.type()).toBe('prompt');
      await dialog.accept('E2E Campaign Assets');
    });

    await page.getByRole('button', { name: /^new$/i }).click();
    await expect(page.getByText('E2E Campaign Assets', { exact: true })).toBeVisible();
  });

  test('filters folder tree by name', async ({ page }) => {
    const search = page.getByPlaceholder('Search folders...');
    await search.fill('E2E Campaign Assets');
    await expect(page.getByText('E2E Campaign Assets', { exact: true })).toBeVisible();
  });

  test('requires a selected folder before upload-to-folder', async ({ page }) => {
    page.on('dialog', async (dialog) => {
      expect(dialog.message()).toContain('Please select a folder first');
      await dialog.accept();
    });

    await page.getByRole('button', { name: /^upload$/i }).click();
  });
});
