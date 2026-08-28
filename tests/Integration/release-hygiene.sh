#!/usr/bin/env bash
# Enforce properties that distinguish a source checkout from a distributable app.
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"

# Source and generated package must never be conflated. The signer creates the sole
# signature.json only inside the disposable build staging directory.
[[ ! -e build ]] || { echo 'build/ must not be committed or present in a source archive.' >&2; exit 1; }
if find . -path './.git' -prune -o -name signature.json -print | grep -q .; then
    echo 'Source tree contains a signature.json; only post-signing staging may contain one.' >&2
    exit 1
fi
matches="$(grep -rl --include='*.php' '\$_SERVER\[.QUERY_STRING.' lib 2>/dev/null || true)"
[[ "$matches" == 'lib/Service/RawQueryService.php' ]] || { echo 'QUERY_STRING is permitted only in RawQueryService.' >&2; printf '%s\n' "$matches" >&2; exit 1; }
for forbidden in signing.key signing.crt release-version.txt; do
    [[ ! -e "$forbidden" ]] || { echo "Ephemeral release material exists in source tree: $forbidden" >&2; exit 1; }
done

# A scheduled compatibility run may publish only after the complete successful
# Unit -> integration -> Kimai browser E2E chain. It must release only when the
# newly tested runtime compatibility differs from the recorded markers.
release=.github/workflows/release.yml
grep -q '^  workflow_run:' "$release" || { echo 'Automated compatibility release must follow successful Kimai E2E.' >&2; exit 1; }
grep -q 'workflows: \["Kimai SAML end-to-end test"\]' "$release" || { echo 'Release must follow Kimai E2E.' >&2; exit 1; }
grep -q 'Every successful main push and every successful scheduled compatibility' "$release" || { echo 'Release must run after every complete green main chain.' >&2; exit 1; }
grep -q 'Preflight required release secrets' "$release" || { echo 'Release must preflight required signing secrets.' >&2; exit 1; }
grep -q 'NEXTCLOUD_SIGNING_PRIVATE_KEY' "$release" || { echo 'Release signing private-key preflight missing.' >&2; exit 1; }
grep -q 'NEXTCLOUD_SIGNING_CERTIFICATE' "$release" || { echo 'Release signing certificate preflight missing.' >&2; exit 1; }
grep -q 'NEXTCLOUD_APPSTORE_TOKEN' "$release" || { echo 'Release App Store token preflight missing.' >&2; exit 1; }
grep -q 'Upload signed archive to Nextcloud App Store' "$release" || { echo 'Release must upload the signed archive to the App Store.' >&2; exit 1; }
grep -q 'appstore_token: ${{ secrets.NEXTCLOUD_APPSTORE_TOKEN }}' "$release" || { echo 'App Store upload must use NEXTCLOUD_APPSTORE_TOKEN.' >&2; exit 1; }
grep -q '\[skip automated release\]' "$release" || { echo 'Release must prevent self-triggered release loops.' >&2; exit 1; }
grep -q 'environment: release' "$release" || { echo 'Release must use protected release environment.' >&2; exit 1; }
grep -q 'scripts/prepare-release.php' "$release" || { echo 'Release must update compatibility metadata.' >&2; exit 1; }
grep -Fq 'git diff --quiet HEAD^ HEAD -- appinfo/info.xml' "$release" || { echo 'Release must detect a version already changed in the tested commit.' >&2; exit 1; }
grep -Fq 'preserving its existing version' "$release" || { echo 'Release must preserve a manually changed app version instead of incrementing twice.' >&2; exit 1; }
grep -Fq 'auto-bump' scripts/prepare-release.php || { echo 'Release helper must distinguish automatic and pre-existing versions.' >&2; exit 1; }
# Coverage is a release-blocking quality gate, not an informational report.
grep -Fq 'php tests/check-coverage.php build/coverage/clover.xml 80' composer.json || { echo 'Production coverage must enforce the 80% threshold.' >&2; exit 1; }
grep -Fq 'name: Enforce minimum 80% production statement coverage (blocking)' .github/workflows/tests.yml || { echo 'Unit workflow must include the named blocking coverage gate.' >&2; exit 1; }
grep -Fq 'codecov/codecov-action@v6' .github/workflows/tests.yml || { echo 'Unit workflow must upload Clover coverage through Codecov.' >&2; exit 1; }
grep -Fq 'token: ${{ secrets.CODECOV_TOKEN }}' .github/workflows/tests.yml || { echo 'Codecov upload must use the CODECOV_TOKEN secret.' >&2; exit 1; }
grep -Fq 'codecov.io/gh/derStephan/nextcloud-SAML-provider/graph/badge.svg' README.md || { echo 'README must display the Codecov coverage badge.' >&2; exit 1; }
! grep -Fq '## Test evidence artifacts' README.md || { echo 'Evidence artifact instructions belong in the Test Contract, not the README.' >&2; exit 1; }
grep -Fq 'run: composer test:coverage' .github/workflows/tests.yml || { echo 'Unit workflow must run the blocking coverage command.' >&2; exit 1; }

# Static source checks are intentionally not executed as functional evidence here.
# Their corresponding requirements are asserted against live Nextcloud/Kimai flows.
grep -Fq 'check-public-ocp-inventory.py' tests/Integration/public-api-preflight.sh || { echo 'Every production OCP import must be declared in the runtime API contract.' >&2; exit 1; }
grep -Fq 'wantAssertionsSigned: true' tests/E2E/kimai-saml.sh || { echo 'Kimai E2E must require signed assertions.' >&2; exit 1; }
grep -Fq 'wantMessagesSigned: true' tests/E2E/kimai-saml.sh || { echo 'Kimai E2E must require signed responses.' >&2; exit 1; }
grep -Fq 'run_browser tampered' tests/E2E/kimai-saml.sh || { echo 'Kimai E2E must reject a tampered response.' >&2; exit 1; }
grep -Fq 'isProtectedKimai' tests/E2E/kimai-saml-browser.mjs || { echo 'Kimai E2E must prove a protected authenticated session.' >&2; exit 1; }
grep -Fq 'wizard: false' tests/E2E/kimai-saml.sh || { echo 'Dynamic Kimai E2E container must disable its optional first-run wizard.' >&2; exit 1; }
! grep -Fq 'isKimaiWizard' tests/E2E/kimai-saml-browser.mjs || { echo 'Kimai E2E must not automate unrelated first-run wizard UI.' >&2; exit 1; }
grep -Fq 'requestProtectedKimaiPage' tests/E2E/kimai-saml-browser.mjs || { echo 'Kimai E2E must prove a protected route through HTTP semantics.' >&2; exit 1; }
! grep -Fq 'hasVisibleLogout' tests/E2E/kimai-saml-browser.mjs || { echo 'Kimai E2E session proof must not depend on UI labels.' >&2; exit 1; }
grep -Fq 'Upload SAML protocol and browser evidence' .github/workflows/kimai-saml-e2e.yml || { echo 'Kimai E2E must upload protocol and browser evidence for every run.' >&2; exit 1; }
grep -Fq 'On failure additionally retain docker-ps' tests/TEST_CONTRACT.md || { echo 'Test Contract must define failure diagnostics artifacts.' >&2; exit 1; }
grep -Fq 'This rule applies to every existing and future test' tests/TEST_CONTRACT.md || { echo 'Test Contract must make Docker log hygiene universal.' >&2; exit 1; }
grep -Fq 'Do not emit `docker logs` on success' tests/TEST_CONTRACT.md || { echo 'Test Contract must prohibit routine Docker log flooding.' >&2; exit 1; }
grep -Fq 'Retain full unfiltered container logs as failure artifacts' tests/TEST_CONTRACT.md || { echo 'Test Contract must preserve complete failure logs as artifacts.' >&2; exit 1; }
grep -Fq 'browser-flow traces for negative, positive, and tampered sessions' tests/TEST_CONTRACT.md || { echo 'Test Contract must define browser-flow artifacts.' >&2; exit 1; }
[[ -f AGENTS.md ]] || { echo 'Missing repository-wide AGENTS.md instructions.' >&2; exit 1; }
[[ -f .github/copilot-instructions.md ]] || { echo 'Missing GitHub Copilot repository instructions.' >&2; exit 1; }
[[ -f .github/instructions/testing.instructions.md ]] || { echo 'Missing path-specific testing instructions.' >&2; exit 1; }
grep -Fq 'tests/TEST_CONTRACT.md' AGENTS.md || { echo 'AGENTS.md must direct agents to the canonical Test Contract.' >&2; exit 1; }
grep -Fq 'tests/TEST_CONTRACT.md' .github/copilot-instructions.md || { echo 'Copilot instructions must direct agents to the canonical Test Contract.' >&2; exit 1; }
grep -Fq 'applyTo: "tests/**,.github/workflows/**,composer.json,phpunit.xml.dist"' .github/instructions/testing.instructions.md || { echo 'Testing instructions must apply to test and CI files.' >&2; exit 1; }
grep -Fq 'tests/TEST_CONTRACT.md' tests/Integration/print-test-contract.sh || { echo 'CI contract wrapper must print the canonical Test Contract.' >&2; exit 1; }
! grep -Fq "cat <<'CONTRACT'" tests/Integration/print-test-contract.sh || { echo 'CI contract wrapper must not duplicate canonical contract text.' >&2; exit 1; }
python3 tests/Integration/check-localization.py >/dev/null || { echo 'All shipped UI catalogs must be complete.' >&2; exit 1; }
grep -Fq 'NEXTCLOUD LIVE PROTOCOL CONTRACT' tests/E2E/kimai-saml.sh || { echo 'Live metadata and NameID protocol checks are required.' >&2; exit 1; }
grep -Fq 'NEXTCLOUD LIVE SIGNATURE POLICY CONTRACT' tests/Integration/smoke.sh || { echo 'Integration suite must prove live signature policy enforcement.' >&2; exit 1; }
grep -Fq 'unsigned Redirect and POST requests rejected' tests/Integration/smoke.sh || { echo 'Signature policy contract must cover both unsigned bindings.' >&2; exit 1; }
grep -Fq 'signed Redirect and POST requests reached login continuation' tests/Integration/smoke.sh || { echo 'Signature policy contract must cover both signed bindings.' >&2; exit 1; }
! grep -Fq 'cu""rl' tests/Integration/smoke.sh || { echo 'Security contract must not obfuscate HTTP client invocation.' >&2; exit 1; }
grep -Fq 'nameid-unspecified-saml11' tests/E2E/kimai-saml.sh || { echo 'Missing safe SAML 1.1 artifact label.' >&2; exit 1; }
grep -Fq 'nameid-unspecified-saml20' tests/E2E/kimai-saml.sh || { echo 'Missing safe SAML 2.0 artifact label.' >&2; exit 1; }
grep -Fq 'probe_sso_login_redirect' tests/E2E/kimai-saml.sh || { echo 'Supported NameID policies must use a real SAML Redirect-binding probe.' >&2; exit 1; }
grep -Fq '^30[23]' tests/E2E/kimai-saml.sh || { echo 'Supported login probe must accept only Nextcloud login redirect statuses.' >&2; exit 1; }
grep -Fq 'Login redirect does not preserve the SAML HTTP-Redirect request' tests/E2E/kimai-saml.sh || { echo 'Supported login probe must verify preserved SAML continuation.' >&2; exit 1; }
grep -Fq -- '--user "$(id -u):$(id -g)"' tests/E2E/kimai-saml.sh || { echo 'Missing explicit artifact writer identity.' >&2; exit 1; }
grep -Fq 'unsupported_nameid-format' tests/E2E/kimai-saml.sh || grep -Fq 'unsupported-nameid-format' tests/E2E/kimai-saml.sh || { echo 'Live unsupported NameID rejection test is required.' >&2; exit 1; }
grep -Fq 'public-ocp-api.json' tests/Integration/nextcloud-api-contract.php || { echo 'Runtime API preflight must consume the machine-readable OCP specification.' >&2; exit 1; }
grep -Fq 'SAMLRequest is missing IssueInstant' lib/Service/SamlService.php || { echo 'SAML parser must reject missing IssueInstant.' >&2; exit 1; }
grep -Fq 'IdP signing certificate is unavailable or expired' lib/Service/SamlService.php || { echo 'Response signing must require a usable current IdP certificate.' >&2; exit 1; }
grep -Fq "root / 'templates'" tests/Integration/check-public-ocp-inventory.py || { echo 'OCP inventory must include templates.' >&2; exit 1; }
grep -Fq 'method_calls' tests/Integration/check-public-ocp-inventory.py || { echo 'OCP inventory must inspect fully-qualified OCP method calls.' >&2; exit 1; }
grep -Fq 'getL10N' tests/Integration/public-ocp-api.json || { echo 'Template OCP getL10N call must be declared.' >&2; exit 1; }

# Integration failure propagation and schema checks must use only public behavior.
[[ ! -e tests/Integration/prepare-version0002-upgrade.php && ! -e tests/Integration/upgrade-index-contract.php ]] || { echo 'Unsupported destructive schema probe remains.' >&2; exit 1; }
for source in tests/Integration/*.php tests/Integration/smoke.sh; do
  [[ -f "$source" ]] || continue
  case "$(basename "$source")" in public-api-preflight.sh|release-hygiene.sh) continue ;; esac
  if grep -Eq 'getPrefix|getSchemaManager|listTableIndexes|listTableColumns|ConnectionAdapter' "$source"; then
    echo "Integration test contains unsupported/private schema API usage: $source" >&2
    exit 1
  fi
done
grep -Fq 'status=$?' tests/Integration/smoke.sh || { echo 'Integration runner must capture the direct CLI exit status.' >&2; exit 1; }
grep -Fq 'if (( status != 0 )); then' tests/Integration/smoke.sh || { echo 'Integration runner must fail immediately on a contract failure.' >&2; exit 1; }
grep -Fq 'policy_status=$?' tests/Integration/smoke.sh || { echo 'Entity-policy preparation must capture its direct CLI exit status.' >&2; exit 1; }
# Test coverage follows maintained Nextcloud releases and the newest available
# RC/Beta at run time. The protected manual release still records the explicit
# range that was proven by that successful run; it must not derive metadata itself.
for workflow in .github/workflows/nextcloud-integration.yml .github/workflows/kimai-saml-e2e.yml; do
    grep -q 'endoflife\.date/api/nextcloud.json' "$workflow" || { echo "Maintained Nextcloud discovery missing in $workflow" >&2; exit 1; }
    grep -q 'hub.docker.com/v2/repositories/library/nextcloud/tags' "$workflow" || { echo "Nextcloud RC/Beta discovery missing in $workflow" >&2; exit 1; }
done
grep -q 'endoflife.date/api/php.json' .github/workflows/release.yml || { echo 'Release must discover maintained PHP releases.' >&2; exit 1; }
grep -q 'endoflife.date/api/nextcloud.json' .github/workflows/release.yml || { echo 'Release must discover maintained Nextcloud releases.' >&2; exit 1; }

# Package scripts are allowlist based and must keep source-only trees out.
grep -q 'for entry in appinfo css img js l10n lib templates LICENSE' scripts/create-appstore-package.sh || { echo 'Runtime package allowlist missing.' >&2; exit 1; }
grep -q 'Missing post-signing appinfo/signature.json' scripts/verify-appstore-package.sh || { echo 'Post-signing signature check missing.' >&2; exit 1; }
echo 'Repository and release hygiene checks passed.'
