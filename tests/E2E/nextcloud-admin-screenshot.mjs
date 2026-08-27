import { chromium } from 'playwright';
import { mkdir, copyFile, writeFile } from 'node:fs/promises';

const artifactDirectory = process.env.E2E_ARTIFACT_DIR || '/work/browser-artifacts';
const documentationScreenshot = process.env.E2E_DOCUMENTATION_SCREENSHOT || '';
const nextcloudOrigin = 'http://e2e-nextcloud';
const adminSettingsUrl = `${nextcloudOrigin}/settings/admin/saml_provider`;
const screenshot = `${artifactDirectory}/nextcloud-saml-provider-admin-e2e.png`;

await mkdir(artifactDirectory, { recursive: true });
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 1100 } });
const page = await context.newPage();
page.setDefaultTimeout(25_000);

try {
  await page.goto(`${nextcloudOrigin}/login`, { waitUntil: 'domcontentloaded' });
  await page.locator('input:not([type]), input[type="text"], input[type="email"]').first().fill('admin');
  await page.locator('input[type="password"]').first().fill('integration-test-password');
  await page.locator('button[type="submit"], input[type="submit"]').first().click();
  await page.waitForURL((url) => url.origin === nextcloudOrigin && !url.pathname.startsWith('/login'), { timeout: 25_000 });

  // This is the documented, user-visible admin settings route. The text assertion
  // proves that the app rendered populated settings, not merely a Nextcloud shell.
  await page.goto(adminSettingsUrl, { waitUntil: 'networkidle' });
  await page.getByText('Your Nextcloud as identity provider', { exact: true }).waitFor({ state: 'visible' });
  await page.getByText('Kimai E2E', { exact: true }).waitFor({ state: 'visible' });
  await page.screenshot({ path: screenshot, fullPage: true });
  await writeFile(`${artifactDirectory}/nextcloud-saml-provider-admin-e2e.json`, JSON.stringify({
    url: page.url(),
    assertions: ['IdP settings visible', 'Kimai E2E service provider visible'],
  }, null, 2));
  if (documentationScreenshot) await copyFile(screenshot, documentationScreenshot);
  console.log(`Captured populated Nextcloud SAML Provider admin settings at ${page.url()}`);
} catch (error) {
  await page.screenshot({ path: `${artifactDirectory}/nextcloud-saml-provider-admin-e2e-failure.png`, fullPage: true }).catch(() => {});
  throw error;
} finally {
  await context.close();
  await browser.close();
}
