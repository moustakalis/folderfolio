import { Page } from '@playwright/test';

export async function loginAsAdmin(page: Page): Promise<void> {
  const username = process.env.WP_ADMIN_USER || 'admin';
  const password = process.env.WP_ADMIN_PASSWORD || 'password';

  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email address/i).fill(username);
  await page.getByLabel(/password/i).fill(password);
  await page.getByRole('button', { name: /log in/i }).click();
  await page.waitForURL(/wp-admin/);
}
