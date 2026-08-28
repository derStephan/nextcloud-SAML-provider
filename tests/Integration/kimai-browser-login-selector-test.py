#!/usr/bin/env python3
"""Guard robust Nextcloud login field targeting in the Kimai browser E2E test."""
from pathlib import Path
source = (Path(__file__).resolve().parents[1] / 'E2E' / 'kimai-saml-browser.mjs').read_text()
required = [
    '#user, input[name="user"], input[autocomplete="username"]',
    '#password, input[name="password"], input[autocomplete="current-password"], input[type="password"]',
    'nextcloud-login-form-not-ready',
    'Kimai SAML request did not reach the Nextcloud login route',
    'nextcloud-login-redirect-not-completed',
    'inputs=${JSON.stringify(state.inputs)}',
    'no Kimai ACS request occurs',
]
missing = [item for item in required if item not in source]
assert not missing, missing
assert "await password.waitFor({ state: 'visible' });\n    note('invalid-nextcloud-login-rejected'" not in source
print('Kimai browser E2E login selector and negative-flow contract passed.')
