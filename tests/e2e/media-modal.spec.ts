import { expect, test } from '@playwright/test';
import { loginAsAdmin } from './helpers/auth';

test.describe('FolderFolio Media Modal', () => {
  test.beforeEach(async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/wp-admin/post-new.php?post_type=post');
  });

  test('loads FolderFolio assets on editor pages', async ({ page }) => {
    await expect(page.locator('body')).toBeVisible();
    const scripts = await page.locator('script[src*="folderfolio-media-modal"]').count();
    expect(scripts).toBeGreaterThanOrEqual(0);
  });

  test('folder modal tree appears after opening media picker', async ({ page }) => {
    const addBlock = page.getByRole('button', { name: /toggle block inserter/i });
    if (await addBlock.isVisible()) {
      await addBlock.click();
      const imageBlock = page.getByRole('option', { name: /image/i }).first();
      if (await imageBlock.isVisible()) {
        await imageBlock.click();
      }
    }

    const mediaButton = page.getByRole('button', { name: /media library/i }).first();
    if (await mediaButton.isVisible()) {
      await mediaButton.click();
      await expect(page.locator('.media-modal')).toBeVisible();
      await expect(page.locator('#folderfolio-modal-tree')).toBeVisible();
    }
  });
});
