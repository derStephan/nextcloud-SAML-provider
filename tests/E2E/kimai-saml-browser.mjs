import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';

const artifactDirectory = process.env.E2E_ARTIFACT_DIR || '/work/browser-artifacts';
const mode = process.env.E2E_SSO_MODE || 'positive';
const kimaiUrl = 'http://e2e-kimai:8001/auth/saml/login';
const protectedKimaiUrl = 'http://e2e-kimai:8001/en/homepage';
const expectedKimaiOrigin = 'http://e2e-kimai:8001';
const expectedNextcloudOrigin = 'http://e2e-nextcloud';
let acsStatus = null;
let tamperedPostObserved = false;
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
// Kimai may redirect /en/homepage to a configured working page such as
// /en/timesheet/. Prove authentication via transport semantics rather than its UI:
// final same-origin /en/ application route plus HTTP 200 after all redirects.
const isProtectedKimai = (url) => url.origin === expectedKimaiOrigin
  && url.pathname.startsWith('/en/')
  && !url.pathname.startsWith('/en/wizard/')
  && !url.pathname.startsWith('/en/login');
async function requestProtectedKimaiPage() {
  const response = await page.goto(protectedKimaiUrl, { waitUntil: 'domcontentloaded' });
  const finalUrl = new URL(page.url());
  const status = response?.status() ?? null;
  const authenticated = isProtectedKimai(finalUrl) && status === 200;
  note('protected-kimai-page-request', { requestedUrl: protectedKimaiUrl, finalUrl: page.url(), status, authenticated });
  return authenticated;
}


if (mode === 'tampered') {
  await page.route('**/auth/saml/acs', async (route) => {
    const body = route.request().postData() || '';
    const match = body.match(/(^|&)SAMLResponse=([^&]+)/);
    if (!match) throw new Error('Expected SAMLResponse POST body was not observed for tampering.');
    const encoded = match[2];
    const xml = Buffer.from(decodeURIComponent(encoded.replace(/\+/g, ' ')), 'base64').toString('utf8');
    // Preserve well-formed XML and base64 encoding while changing a signed value.
    // A strict SP must reject this because the assertion digest/signature no longer matches.
    const tamperedXml = xml.replace(/(<saml2:NameID[^>]*>)[^<]+/, '$1tampered-nameid');
    if (tamperedXml === xml) throw new Error('Could not locate a signed NameID value to tamper with.');
    const replacement = encodeURIComponent(Buffer.from(tamperedXml, 'utf8').toString('base64'));
    tamperedPostObserved = true;
    note('tampered-saml-response-post', { bytes: body.length, mutation: 'signed-nameid' });
    await route.continue({ postData: body.replace(`SAMLResponse=${encoded}`, `SAMLResponse=${replacement}`) });
  });
}

try {
  await page.goto(kimaiUrl, { waitUntil: 'domcontentloaded' });
  if (/^http:\/\/e2e-nextcloud\/https?:\/\//.test(page.url())) throw new Error(`Kimai built an invalid IdP redirect: ${page.url()}. Check saml.connection.baseurl.`);
  await page.waitForURL((url) => url.origin === expectedNextcloudOrigin && url.pathname.startsWith('/login'), { timeout: 25_000 }).catch(async () => {
    const state = await page.evaluate(() => ({ title: document.title, text: document.body?.innerText.slice(0, 2_000) || '' }));
    note('nextcloud-login-redirect-not-completed', { mode, url: page.url(), ...state });
    throw new Error(`Kimai SAML request did not reach the Nextcloud login route; final URL: ${page.url()}`);
  });
  const username = page.locator('#user, input[name="user"], input[autocomplete="username"]').first();
  const password = page.locator('#password, input[name="password"], input[autocomplete="current-password"], input[type="password"]').first();
  await Promise.all([username.waitFor({ state: 'visible', timeout: 25_000 }), password.waitFor({ state: 'visible', timeout: 25_000 })]);
  await username.fill('admin');
  await password.fill(mode === 'negative' ? 'deliberately-wrong-password' : 'integration-test-password');
  await page.locator('button[type="submit"], input[type="submit"]').first().click();
  note('nextcloud-login-submitted', { mode, url: page.url() });

  if (mode === 'negative') {
    await page.waitForTimeout(2_000);
    const current = new URL(page.url());
    if (current.origin !== expectedNextcloudOrigin || !current.pathname.startsWith('/login')) throw new Error(`Invalid Nextcloud credentials escaped the login page: ${page.url()}`);
    if (acsStatus !== null) throw new Error(`Invalid Nextcloud credentials reached Kimai ACS (HTTP ${acsStatus}).`);
    note('invalid-nextcloud-login-rejected', { url: page.url() });
    await snapshot('invalid-nextcloud-login-rejected');
  } else if (mode === 'tampered') {
    await page.waitForTimeout(3_000);
    if (!tamperedPostObserved || acsStatus === null) throw new Error('The tampered SAMLResponse did not reach Kimai ACS.');
    if (await requestProtectedKimaiPage()) throw new Error('Kimai retained an authenticated session after rejecting a tampered SAMLResponse.');
    note('tampered-saml-response-rejected', { acsStatus, finalUrl: page.url() });
    await snapshot('tampered-saml-response-rejected');
  } else {
    // The dynamically pulled Kimai image disables its optional first-run wizard via
    // kimai.user.wizard: false. A successful SAML flow must reach a protected route
    // directly; Kimai may choose a user-specific landing page such as the timesheet.
    await page.waitForURL((url) => isProtectedKimai(url), { timeout: 45_000 });
    if (acsStatus === null || acsStatus < 300 || acsStatus >= 400) throw new Error(`Kimai did not accept the signed SAML POST with a redirect (ACS status: ${acsStatus ?? 'not observed'}).`);
    if (!await requestProtectedKimaiPage()) throw new Error(`SAML login did not establish a protected Kimai HTTP 200 application session: ${page.url()}`);
    note('kimai-signed-saml-authenticated', { acsStatus, authenticatedUrl: page.url(), protectedHttpStatus: 200 });
    await snapshot('kimai-authenticated');
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
