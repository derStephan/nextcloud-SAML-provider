import { chromium } from 'playwright';
import { mkdir } from 'node:fs/promises';

const artifactDirectory = process.env.E2E_ARTIFACT_DIR || '/work/browser-artifacts';
const kimaiUrl = 'http://e2e-kimai:8001/auth/saml/login';
const expectedKimaiOrigin = 'http://e2e-kimai:8001';

await mkdir(artifactDirectory, { recursive: true });
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();
page.setDefaultTimeout(20_000);

try {
  await page.goto(kimaiUrl, { waitUntil: 'domcontentloaded' });
  // Target visible credential controls, never Nextcloud template markup, hidden
  // request tokens, or generated form actions. Chromium executes the real flow.
  const password = page.locator('input[type="password"]').first();
  await password.waitFor({ state: 'visible' });
  const username = page.locator('input:not([type]), input[type="text"], input[type="email"]').first();
  await username.waitFor({ state: 'visible' });
  await username.fill('admin');
  await password.fill('integration-test-password');
  await Promise.all([
    page.waitForURL((url) => url.origin === expectedKimaiOrigin && !url.pathname.startsWith('/auth/saml'), { timeout: 45_000 }),
    page.locator('button[type="submit"], input[type="submit"]').first().click(),
  ]);
  await page.screenshot({ path: `${artifactDirectory}/kimai-authenticated.png`, fullPage: true });
  console.log(`Browser SSO completed at ${page.url()}`);
} catch (error) {
  await page.screenshot({ path: `${artifactDirectory}/kimai-sso-failure.png`, fullPage: true }).catch(() => {});
  console.error(`Browser SSO failed at ${page.url()}`);
  throw error;
} finally {
  await context.close();
  await browser.close();
}
