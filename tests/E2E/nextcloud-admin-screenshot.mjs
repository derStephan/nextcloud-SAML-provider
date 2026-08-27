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
  const adminRoot = page.locator('#saml-provider-admin-settings');
  await adminRoot.waitFor({ state: 'visible' });
  await page.getByText('Your Nextcloud as identity provider', { exact: true }).waitFor({ state: 'visible' });
  await page.getByText('Kimai E2E', { exact: true }).waitFor({ state: 'visible' });

  // `networkidle` does not guarantee that Nextcloud's animated startup overlay
  // has left the viewport. A fixed overlay can otherwise produce a technically
  // correct but visually unusable screenshot. Wait for the real, visible page:
  // the viewport centre must belong to this app's rendered settings root, and no
  // visible loading/loader element may cover most of the viewport.
  await page.waitForFunction(() => {
    const root = document.getElementById('saml-provider-admin-settings');
    const centre = document.elementFromPoint(window.innerWidth / 2, window.innerHeight / 2);
    if (!root || !centre || !root.contains(centre)) return false;
    const viewportArea = window.innerWidth * window.innerHeight;
    return !Array.from(document.querySelectorAll('[id*="loading" i], [class*="loading" i], [id*="loader" i], [class*="loader" i]'))
      .some((element) => {
        const style = window.getComputedStyle(element);
        const box = element.getBoundingClientRect();
        const visible = style.display !== 'none' && style.visibility !== 'hidden' && Number(style.opacity || 1) > 0;
        return visible && box.width * box.height > viewportArea * 0.6;
      });
  }, { timeout: 25_000 });
  await page.waitForTimeout(250);
  await page.screenshot({ path: screenshot, fullPage: true });
  await writeFile(`${artifactDirectory}/nextcloud-saml-provider-admin-e2e.json`, JSON.stringify({
    url: page.url(),
    assertions: ['IdP settings visible', 'Kimai E2E service provider visible', 'No startup overlay at viewport centre'],
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
