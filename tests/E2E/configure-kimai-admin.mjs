import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';

const artifactDirectory = process.env.E2E_ARTIFACT_DIR || '/work/browser-artifacts';
const output = process.env.E2E_KIMAI_CONFIG || '/work/browser-artifacts/kimai-idp.json';
const nextcloudOrigin = 'http://e2e-nextcloud';
const adminSettingsUrl = `${nextcloudOrigin}/settings/admin/saml_provider`;
const kimai = {
  name: 'Kimai E2E',
  entityId: 'http://e2e-kimai:8001/auth/saml/metadata',
  acsUrl: 'http://e2e-kimai:8001/auth/saml/acs',
};

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
  await page.waitForURL((url) => url.origin === nextcloudOrigin && !url.pathname.startsWith('/login'));
  await page.goto(adminSettingsUrl, { waitUntil: 'networkidle' });
  const root = page.locator('#saml-provider-admin-settings');
  await root.waitFor({ state: 'visible' });

  const certificateButton = page.getByRole('button', { name: 'Generate certificate', exact: true });
  if (await certificateButton.isVisible().catch(() => false)) {
    await certificateButton.click();
    // A toast is transient and translated. The durable product outcome is the
    // re-rendered PEM certificate field, so wait for that instead.
    await page.waitForFunction(() => Array.from(document.querySelectorAll('input.saml-provider-copy'))
      .some((input) => input.value.includes('BEGIN CERTIFICATE')), null, { timeout: 25_000 });
  }
  const certificate = await page.locator('input.saml-provider-copy').evaluateAll((inputs) => {
    const field = inputs.find((input) => input.value.includes('BEGIN CERTIFICATE'));
    return field?.value || '';
  });
  if (!certificate.includes('BEGIN CERTIFICATE')) throw new Error('Admin UI did not expose the generated IdP certificate.');

  await page.locator('#saml-provider-new-sp-name').fill(kimai.name);
  await page.locator('#saml-provider-new-sp-entity-id').fill(kimai.entityId);
  await page.locator('#saml-provider-new-sp-acs-url').fill(kimai.acsUrl);
  // Kimai 2.65 advertises an unspecified NameID in its SP metadata. Create first
  // via the real admin UI, then set that negotiated policy through the rendered
  // service editor before starting Kimai. A mismatch is correctly rejected by SSO
  // before Nextcloud can redirect to its login page.
  await page.getByRole('button', { name: 'Connect service', exact: true }).click();
  const serviceRow = page.getByText('Kimai E2E', { exact: true }).locator('xpath=ancestor::tr');
  await serviceRow.waitFor({ state: 'visible' });
  const detailRow = serviceRow.locator('xpath=following-sibling::tr[1]');
  const details = detailRow.locator('details');
  await details.locator('summary').click();
  const nameId = detailRow.locator('select').first();
  await nameId.waitFor({ state: 'visible' });
  const requiredNameId = 'urn:oasis:names:tc:SAML:2.0:nameid-format:unspecified';
  await nameId.selectOption(requiredNameId);
  const saveResponse = page.waitForResponse((response) =>
    response.url().includes('/apps/saml_provider/settings/sp/update') && response.request().method() === 'POST',
    { timeout: 25_000 },
  );
  await detailRow.getByRole('button', { name: 'Save changes', exact: true }).click();
  const response = await saveResponse;
  if (!response.ok()) throw new Error(`Kimai NameID update failed with HTTP ${response.status()}.`);

  // A click is not evidence of persistence. Reload the real administration page and
  // verify the database-backed service state before Kimai sends an AuthnRequest.
  await page.goto(adminSettingsUrl, { waitUntil: 'networkidle' });
  const persistedRow = page.getByText('Kimai E2E', { exact: true }).locator('xpath=ancestor::tr');
  await persistedRow.waitFor({ state: 'visible' });
  const persistedDetailRow = persistedRow.locator('xpath=following-sibling::tr[1]');
  await persistedDetailRow.locator('details').locator('summary').click();
  const persistedNameId = persistedDetailRow.locator('select').first();
  await persistedNameId.waitFor({ state: 'visible' });
  if (await persistedNameId.inputValue() !== requiredNameId) {
    throw new Error(`Kimai NameID format was not persisted; expected ${requiredNameId}.`);
  }
  await writeFile(output, JSON.stringify({ ...kimai, certificate }, null, 2));
} catch (error) {
  await page.screenshot({ path: `${artifactDirectory}/admin-configuration-failure.png`, fullPage: true }).catch(() => {});
  throw error;
} finally {
  await context.close();
  await browser.close();
}
