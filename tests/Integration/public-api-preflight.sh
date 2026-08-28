#!/usr/bin/env bash
# Stops the build before integration/E2E tests when our code or tests depend on
# private Nextcloud implementation details. OCP is Nextcloud's public PHP API;
# OC and lib/private are deliberately not accepted here.
set -euo pipefail

say() { printf '%s\n' "$*"; }
failures=0
check() {
    local scope="$1" pattern="$2" guidance="$3"
    local hits
    hits="$(rg -n --glob '*.php' --glob '*.sh' --glob '*.mjs' --glob '*.js' --glob '*.yml' --glob '*.yaml' \
        --glob '!public-api-preflight.sh' --glob '!print-test-contract.sh' --glob '!release-hygiene.sh' "$pattern" "$scope" 2>/dev/null || true)"
    if [[ -n "$hits" ]]; then
        printf 'PUBLIC-API PREFLIGHT FAILED: private or unstable upstream API reference in %s:\n%s\nHow to fix: %s\n\n' \
            "$scope" "$hits" "$guidance" >&2
        failures=1
    fi
}

say '================================================================='
say 'PUBLIC API PREFLIGHT: scanning application code and test code'
say 'Rule: production and tests may use OCP, documented HTTP endpoints, and documented CLIs; OC and lib/private are forbidden.'
say '================================================================='
# Exact internal namespaces/service locators. This does not reject the OCP public namespace.
check lib '(\\\\?OC\\\\|\\bOC::|lib/private|ContentSecurityPolicyNonceManager)' \
    'Replace the internal dependency with a documented OCP interface or a response/template extension point.'
check tests '(\\\\?OC\\\\|\\bOC::|lib/private|ContentSecurityPolicyNonceManager)' \
    'Remove the private API from the test. Assert a public OCP, HTTP, CLI, or browser-observable outcome instead.'
check tests 'getSchemaManager|listTableIndexes|listTableColumns|ConnectionAdapter' \
    'Schema-manager/Doctrine internals are forbidden. Use only verified public OCP APIs; run real migrations through occ and assert outcomes via IDBConnection or public HTTP endpoints.'
check lib 'getSchemaManager|listTableIndexes|listTableColumns|ConnectionAdapter' \
    'Schema-manager/Doctrine internals are forbidden. Use ISchemaWrapper only inside SimpleMigrationStep or a documented OCP replacement.'

if [[ "$failures" -ne 0 ]]; then
    say 'PUBLIC API PREFLIGHT RESULT: FAILED. The functional tests were intentionally not started.' >&2
    exit 1
fi
python3 tests/Integration/check-public-ocp-inventory.py
say 'PUBLIC API PREFLIGHT RESULT: PASSED. No private Nextcloud API references were found and all production OCP imports are declared.'
