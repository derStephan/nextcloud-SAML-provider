import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';

const artifactDirectory = process.env.E2E_ARTIFACT_DIR || '/work/browser-artifacts';
const mode = process.env.E2E_SSO_MODE || 'positive';
const kimaiUrl = 'http://e2e-kimai:8001/auth/saml/login';
const expectedKimaiOrigin = 'http://e2e-kimai:8001';
const expectedNextcloudOrigin = 'http://e2e-nextcloud';
let acsStatus = null;
const trace = [];
const note = (event, details = {}) => trace.push({ time: new Date().toISOString(), event, ...details });

await mkdir(artifactDirectory, { recursive: true });
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();
page.setDefaultTimeout(20_000);
page.on('framenavigated', (frame) => { if (frame === page.mainFrame()) note('navigation', { url: frame.url() }); });
page.on('response', (response) => {
  const url = response.url();
  if (url.includes('/auth/saml/') || url.includes('/apps/saml_provider/') || url.includes('/login')) note('response', { status: response.status(), url });
  if (url === 'http://e2e-kimai:8001/auth/saml/acs') acsStatus = response.status();
});
page.on('console', (message) => note('browser-console', { type: message.type(), text: message.text() }));
page.on('pageerror', (error) => note('browser-page-error', { message: error.message }));

async function snapshot(name) {
  const title = await page.title().catch(() => '');
  const html = await page.content().catch(() => '');
  await page.screenshot({ path: `${artifactDirectory}/${name}.png`, fullPage: true }).catch(() => {});
  await writeFile(`${artifactDirectory}/${name}.html`, html.slice(0, 200_000));
  note('snapshot', { name, url: page.url(), title });
}
const kimaiAuthenticated = (url) => url.origin === expectedKimaiOrigin && !url.pathname.startsWith('/auth/saml');

try {
  await page.goto(kimaiUrl, { waitUntil: 'domcontentloaded' });
  if (/^http:\/\/e2e-nextcloud\/https?:\/\//.test(page.url())) throw new Error(`Kimai built an invalid IdP redirect: ${page.url()}. Check saml.connection.baseurl.`);
  const password = page.locator('input[type="password"]').first();
  await password.waitFor({ state: 'visible' });
  const username = page.locator('input:not([type]), input[type="text"], input[type="email"]').first();
  await username.waitFor({ state: 'visible' });
  note('nextcloud-login-form-ready', { mode, url: page.url(), title: await page.title() });
  await username.fill('admin');
  await password.fill(mode === 'negative' ? 'deliberately-wrong-password' : 'integration-test-password');
  await page.locator('button[type="submit"], input[type="submit"]').first().click();
  note('nextcloud-login-submitted', { mode, url: page.url() });

  if (mode === 'negative') {
    // An invalid IdP login must remain at Nextcloud and must never invoke Kimai ACS.
    await page.waitForTimeout(2_000);
    const current = new URL(page.url());
    if (current.origin !== expectedNextcloudOrigin || !current.pathname.startsWith('/login')) {
      throw new Error(`Invalid Nextcloud credentials escaped the login page: ${page.url()}`);
    }
    if (acsStatus !== null) throw new Error(`Invalid Nextcloud credentials reached Kimai ACS (HTTP ${acsStatus}).`);
    await password.waitFor({ state: 'visible' });
    note('invalid-nextcloud-login-rejected', { url: page.url() });
    await snapshot('invalid-nextcloud-login-rejected');
    console.log('Invalid Nextcloud login was rejected without a Kimai ACS request.');
  } else {
    const samlPostForm = page.locator('#saml-provider-post-form');
    const firstOutcome = await Promise.race([
      page.waitForURL(kimaiAuthenticated, { timeout: 15_000 }).then(() => 'kimai'),
      samlPostForm.waitFor({ state: 'visible', timeout: 15_000 }).then(() => 'form'),
    ]);
    if (firstOutcome === 'form') {
      note('nextcloud-saml-post-form-visible', { url: page.url() });
      await page.waitForTimeout(750);
      if (!kimaiAuthenticated(new URL(page.url()))) {
        await snapshot('nextcloud-saml-post-ready');
        const continueButton = samlPostForm.locator('button[type="submit"], input[type="submit"]').first();
        if (await continueButton.isVisible()) {
          note('nextcloud-saml-post-fallback-click', { url: page.url() });
          await continueButton.click();
        }
      }
    } else {
      note('nextcloud-saml-auto-submit-completed', { url: page.url() });
    }
    if (!kimaiAuthenticated(new URL(page.url()))) await page.waitForURL(kimaiAuthenticated, { timeout: 45_000 });
    if (acsStatus === null || acsStatus < 300 || acsStatus >= 400) throw new Error(`Kimai did not accept the browser SAML POST with a redirect (ACS status: ${acsStatus ?? 'not observed'}).`);
    note('kimai-browser-acs-accepted', { acsStatus, authenticatedUrl: page.url() });
    await snapshot('kimai-authenticated');
    console.log(`Browser SSO completed at ${page.url()} after ACS HTTP ${acsStatus}`);
  }
} catch (error) {
  await snapshot(`kimai-sso-${mode}-failure`);
  console.error(`Browser ${mode} SSO flow failed at ${page.url()}`);
  throw error;
} finally {
  await writeFile(`${artifactDirectory}/browser-flow-${mode}.json`, JSON.stringify(trace, null, 2));
  await context.close();
  await browser.close();
}
