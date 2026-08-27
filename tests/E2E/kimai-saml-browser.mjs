import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';
const artifactDirectory = process.env.E2E_ARTIFACT_DIR || '/work/browser-artifacts';
const kimaiUrl = 'http://e2e-kimai:8001/auth/saml/login';
const expectedKimaiOrigin = 'http://e2e-kimai:8001';
const trace = []; const note = (event, details = {}) => trace.push({ time: new Date().toISOString(), event, ...details });
await mkdir(artifactDirectory, { recursive: true });
const browser = await chromium.launch({ headless: true }); const context = await browser.newContext(); const page = await context.newPage(); page.setDefaultTimeout(20_000);
page.on('framenavigated', (frame) => { if (frame === page.mainFrame()) note('navigation', { url: frame.url() }); });
page.on('response', (response) => { const url = response.url(); if (url.includes('/auth/saml/') || url.includes('/apps/saml_provider/') || url.includes('/login')) note('response', { status: response.status(), url }); });
page.on('console', (message) => note('browser-console', { type: message.type(), text: message.text() }));
page.on('pageerror', (error) => note('browser-page-error', { message: error.message }));
async function snapshot(name) { const title = await page.title().catch(() => ''); const html = await page.content().catch(() => ''); await page.screenshot({ path: `${artifactDirectory}/${name}.png`, fullPage: true }).catch(() => {}); await writeFile(`${artifactDirectory}/${name}.html`, html.slice(0, 200_000)); note('snapshot', { name, url: page.url(), title }); }
const kimaiAuthenticated = (url) => url.origin === expectedKimaiOrigin && !url.pathname.startsWith('/auth/saml');
try {
  await page.goto(kimaiUrl, { waitUntil: 'domcontentloaded' });
  if (/^http:\/\/e2e-nextcloud\/https?:\/\//.test(page.url())) throw new Error(`Kimai built an invalid IdP redirect: ${page.url()}. Check saml.connection.baseurl.`);
  const password = page.locator('input[type="password"]').first(); await password.waitFor({ state: 'visible' });
  const username = page.locator('input:not([type]), input[type="text"], input[type="email"]').first(); await username.waitFor({ state: 'visible' });
  note('nextcloud-login-form-ready', { url: page.url(), title: await page.title() }); await username.fill('admin'); await password.fill('integration-test-password');
  await page.locator('button[type="submit"], input[type="submit"]').first().click(); note('nextcloud-login-submitted', { url: page.url() });
  // Nextcloud's app normally auto-submits its own signed POST form. In a headless
  // browser, wait for that first. If its generated form remains visible, activate
  // its existing Continue control; never inspect or reconstruct SAML fields.
  const samlPostForm = page.locator('#saml-provider-post-form');
  await samlPostForm.waitFor({ state: 'visible', timeout: 15_000 });
  await page.waitForTimeout(750);
  if (!kimaiAuthenticated(new URL(page.url()))) {
    await snapshot('nextcloud-saml-post-ready');
    const continueButton = samlPostForm.locator('button[type="submit"], input[type="submit"]').first();
    if (await continueButton.isVisible()) {
      note('nextcloud-saml-post-fallback-click', { url: page.url() });
      await continueButton.click();
    }
  }
  await page.waitForURL(kimaiAuthenticated, { timeout: 45_000 }); await snapshot('kimai-authenticated'); console.log(`Browser SSO completed at ${page.url()}`);
} catch (error) { await snapshot('kimai-sso-failure'); console.error(`Browser SSO failed at ${page.url()}`); throw error; }
finally { await writeFile(`${artifactDirectory}/browser-flow.json`, JSON.stringify(trace, null, 2)); await context.close(); await browser.close(); }
