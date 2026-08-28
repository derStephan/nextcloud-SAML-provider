import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';

const artifactDirectory = process.env.E2E_ARTIFACT_DIR || '/work/browser-artifacts';
const config = JSON.parse(await (await import('node:fs/promises')).readFile(process.env.E2E_KIMAI_CONFIG, 'utf8'));
const origin = 'http://e2e-nextcloud';
const startUrl = `${origin}/apps/saml_provider/saml/login/${config.serviceId}`;
await mkdir(artifactDirectory, { recursive: true });
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage(); page.setDefaultTimeout(25_000);
const trace = []; const note = (event, details = {}) => trace.push({ time: new Date().toISOString(), event, ...details });
try {
  await page.goto(`${origin}/login`, { waitUntil: 'domcontentloaded' });
  await page.locator('#user, input[name="user"], input[autocomplete="username"]').first().fill('admin');
  await page.locator('#password, input[name="password"], input[autocomplete="current-password"]').first().fill('integration-test-password');
  await page.locator('button[type="submit"], input[type="submit"]').first().click();
  await page.waitForURL((url) => url.origin === origin && !url.pathname.startsWith('/login'));
  const missingToken = await context.request.post(`${startUrl}/confirm`, { maxRedirects: 0 });
  note('idp-initiated-missing-csrf', { status: missingToken.status() });
  if (missingToken.status() !== 412) throw new Error(`IdP-initiated POST without requesttoken must be rejected with HTTP 412, got ${missingToken.status()}.`);
  await page.goto(startUrl, { waitUntil: 'domcontentloaded' });
  const form = page.locator('form[action*="/saml/login/"][action$="/confirm"]');
  await form.waitFor({ state: 'visible' });
  const token = page.locator('input[name="requesttoken"]');
  await page.waitForFunction(() => document.querySelector('input[name="requesttoken"]')?.value.length > 0);
  const responsePromise = page.waitForResponse((response) => response.url().includes('/saml/login/') && response.url().endsWith('/confirm') && response.request().method() === 'POST');
  await form.locator('button[type="submit"]').click();
  const confirmed = await responsePromise;
  if (!confirmed.ok()) throw new Error(`CSRF-token-confirmed IdP flow failed with HTTP ${confirmed.status()}.`);
  await page.waitForFunction(() => Boolean(document.querySelector('input[name="SAMLResponse"]')));
  const responseValue = await page.locator('input[name="SAMLResponse"]').inputValue();
  if (responseValue.length < 100) throw new Error('IdP-initiated confirmation did not render a SAMLResponse.');
  await page.screenshot({ path: `${artifactDirectory}/idp-initiated-confirmed.png`, fullPage: true });
  await writeFile(`${artifactDirectory}/idp-initiated-confirmed.html`, (await page.content()).slice(0, 200_000));
  note('idp-initiated-confirmed', { status: confirmed.status(), samlResponseBytes: responseValue.length });
} catch (error) {
  await page.screenshot({ path: `${artifactDirectory}/idp-initiated-failure.png`, fullPage: true }).catch(() => {});
  throw error;
} finally {
  await writeFile(`${artifactDirectory}/browser-flow-idp-initiated.json`, JSON.stringify(trace, null, 2));
  await context.close(); await browser.close();
}
