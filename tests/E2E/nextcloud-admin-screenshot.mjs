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

  // Nextcloud can show a first-run/welcome dialog over an otherwise rendered
  // settings page. Dismiss it through its visible controls first, just as a user
  // would. This must happen before assessing whether the app is unobscured.
  for (const name of [/^(skip|close|not now|got it|continue)$/i, /^(next|done)$/i]) {
    const button = page.getByRole('button', { name }).first();
    if (await button.isVisible().catch(() => false)) await button.click().catch(() => {});
  }
  await page.keyboard.press('Escape').catch(() => {});

  // Some Nextcloud releases retain a startup container after its UI has already
  // rendered. It is not part of the SAML Provider UI and can cover the page in a
  // headless screenshot indefinitely. Remove only a large fixed overlay whose own
  // id/class explicitly identifies it as loading, first-run, welcome, or wizard.
  // Never remove the app root or an arbitrary dialog.
  await page.waitForFunction(() => {
    const root = document.getElementById('saml-provider-admin-settings');
    if (!root) return false;
    const viewportArea = window.innerWidth * window.innerHeight;
    for (const element of Array.from(document.querySelectorAll('body *'))) {
      if (element === root || element.contains(root) || root.contains(element)) continue;
      const marker = `${element.id} ${element.className}`.toLowerCase();
      if (!/(initial|loading|loader|firstrun|first-run|welcome|wizard)/.test(marker)) continue;
      const style = window.getComputedStyle(element);
      const box = element.getBoundingClientRect();
      const fixed = style.position === 'fixed' || style.position === 'absolute';
      const visible = style.display !== 'none' && style.visibility !== 'hidden' && Number(style.opacity || 1) > 0;
      if (fixed && visible && box.width * box.height > viewportArea * 0.25) element.remove();
    }
    const centre = document.elementFromPoint(window.innerWidth / 2, window.innerHeight / 2);
    return Boolean(centre && root.contains(centre));
  }, { timeout: 25_000 });
  await page.waitForTimeout(250);
  await page.screenshot({ path: screenshot, fullPage: true });
  await writeFile(`${artifactDirectory}/nextcloud-saml-provider-admin-e2e.json`, JSON.stringify({
    url: page.url(),
    assertions: ['IdP settings visible', 'Kimai E2E service provider visible', 'First-run and loading overlays dismissed before capture'],
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
