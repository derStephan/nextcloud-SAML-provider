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
  // Do not inspect login controls while a Kimai -> Nextcloud redirect is still in
  // flight. A rejected SAML request remains on the SSO route; report that contract
  // failure directly instead of disguising it as a missing input selector.
  await page.waitForURL((url) => url.origin === expectedNextcloudOrigin && url.pathname.startsWith('/login'), { timeout: 25_000 }).catch(async () => {
    const state = await page.evaluate(() => ({ title: document.title, text: document.body?.innerText.slice(0, 2_000) || '' }));
    note('nextcloud-login-redirect-not-completed', { mode, url: page.url(), ...state });
    throw new Error(`Kimai SAML request did not reach the Nextcloud login route; final URL: ${page.url()}`);
  });
  // Nextcloud's semantic field identifiers are stable across its evolving login
  // markup. Do not rely exclusively on type=password: some supported releases and
  // login variants expose the password field by id/name while the type is applied later.
  const username = page.locator('#user, input[name="user"], input[autocomplete="username"]').first();
  const password = page.locator('#password, input[name="password"], input[autocomplete="current-password"], input[type="password"]').first();
  const loginFormReady = await Promise.all([
    username.waitFor({ state: 'visible', timeout: 25_000 }).then(() => true).catch(() => false),
    password.waitFor({ state: 'visible', timeout: 25_000 }).then(() => true).catch(() => false),
  ]);
  if (!loginFormReady.every(Boolean)) {
    const state = await page.evaluate(() => ({
      title: document.title,
      text: document.body?.innerText.slice(0, 2_000) || '',
      inputs: Array.from(document.querySelectorAll('input')).map((input) => ({
        id: input.id, name: input.getAttribute('name'), type: input.getAttribute('type'),
        autocomplete: input.getAttribute('autocomplete'), visible: !!(input.offsetWidth || input.offsetHeight || input.getClientRects().length),
      })),
    }));
    note('nextcloud-login-form-not-ready', { mode, url: page.url(), ...state });
    throw new Error(`Nextcloud login form did not expose username and password fields at ${page.url()}; inputs=${JSON.stringify(state.inputs)}`);
  }
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
    // The observable security contract is that the failed login stays at Nextcloud
    // and no Kimai ACS request occurs. Nextcloud may render password, passkey, or a
    // throttling/error state afterwards, so do not require a second password-field draw.
    note('invalid-nextcloud-login-rejected', { url: page.url() });
    await snapshot('invalid-nextcloud-login-rejected');
    console.log('Invalid Nextcloud login was rejected without a Kimai ACS request.');
  } else {
    // Positive SSO must auto-submit. Do not click the fallback button here:
    // if the form remains visible, CSP, nonce, or JavaScript execution regressed.
    await page.waitForURL(kimaiAuthenticated, { timeout: 45_000 });
    note('nextcloud-saml-auto-submit-completed', { url: page.url() });
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
