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

# Automatic releases turn test infrastructure into an unreviewed publishing path.
release=.github/workflows/release.yml
grep -q '^  workflow_dispatch:' "$release" || { echo 'Release must require workflow_dispatch.' >&2; exit 1; }
! grep -q '^  workflow_run:' "$release" || { echo 'Release must not be triggered by workflow_run.' >&2; exit 1; }
! grep -q '^  push:' "$release" || { echo 'Release must not be triggered by push.' >&2; exit 1; }
grep -q 'environment: release' "$release" || { echo 'Release must use protected release environment.' >&2; exit 1; }

# Compatibility evidence must be reviewable within the commit, rather than supplied
# by an unauthenticated-at-build-time lifecycle or Docker Hub API response.
# The PHP unit-test matrix intentionally follows currently supported PHP minors.
# Release evidence must remain reviewable, so dynamic Nextcloud/Docker discovery
# is forbidden only in integration, browser-E2E, and release workflows.
for workflow in .github/workflows/nextcloud-integration.yml .github/workflows/kimai-saml-e2e.yml .github/workflows/release.yml; do
    ! grep -Eq 'endoflife\.date|hub\.docker\.com' "$workflow" || { echo "Dynamic release matrix source in $workflow" >&2; exit 1; }
done

grep -q "nextcloud:33-apache" .github/workflows/nextcloud-integration.yml || { echo 'Missing explicit Nextcloud compatibility target.' >&2; exit 1; }
grep -q "nextcloud:34-apache" .github/workflows/nextcloud-integration.yml || { echo 'Missing explicit Nextcloud compatibility target.' >&2; exit 1; }

# Package scripts are allowlist based and must keep source-only trees out.
grep -q 'for entry in appinfo css img js l10n lib templates LICENSE' scripts/create-appstore-package.sh || { echo 'Runtime package allowlist missing.' >&2; exit 1; }
grep -q 'Missing post-signing appinfo/signature.json' scripts/verify-appstore-package.sh || { echo 'Post-signing signature check missing.' >&2; exit 1; }
echo 'Repository and release hygiene checks passed.'
