#!/usr/bin/env python3
"""Guard Kimai NameID negotiation and completed Nextcloud login redirect in E2E."""
from pathlib import Path
root = Path(__file__).resolve().parents[2]
admin = (root / 'tests/E2E/configure-kimai-admin.mjs').read_text()
browser = (root / 'tests/E2E/kimai-saml-browser.mjs').read_text()
required_admin = [
    "following-sibling::tr[1]",
    "details.locator('summary').click()",
    "const requiredNameId = 'urn:oasis:names:tc:SAML:2.0:nameid-format:unspecified';",
    "nameId.selectOption(requiredNameId)",
    "detailRow.getByRole('button', { name: 'Save changes', exact: true }).click()",
    'await persistedNameId.inputValue() !== requiredNameId',
    "const persistedRow = page.getByText('Kimai E2E', { exact: true })",
    'if (!response.ok())',
    "'/apps/saml_provider/settings/sp/update'",
    'page.waitForResponse((response) =>',
]
required_browser = [
    "url.origin === expectedNextcloudOrigin && url.pathname.startsWith('/login')",
    "nextcloud-login-redirect-not-completed",
    "Kimai SAML request did not reach the Nextcloud login route",
]
missing = [x for x in required_admin if x not in admin] + [x for x in required_browser if x not in browser]
assert not missing, missing
assert browser.index("await page.waitForURL((url) => url.origin === expectedNextcloudOrigin") < browser.index("const username = page.locator")
print('Kimai NameID negotiation and Nextcloud login redirect contract passed.')
