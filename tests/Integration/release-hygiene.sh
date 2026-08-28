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
python3 tests/Integration/kimai-certificate-normalization-test.py

# A `with:` map must belong to the current action step. A run step inserted
# between `uses:` and `with:` makes GitHub reject the workflow before tests start.
for workflow in .github/workflows/*.yml; do
    if awk '''
        /^[[:space:]]+- / { action = ($0 ~ /uses:/) }
        /^[[:space:]]+uses:/ { action = 1 }
        /^[[:space:]]+with:/ { if (!action) exit 1 }
    ''' "$workflow"; then :; else
        echo "A with: block must belong to the immediately current uses: step in $workflow" >&2
        exit 1
    fi
done

release=.github/workflows/release.yml
grep -q '^  workflow_dispatch:' "$release" || { echo 'Release must require workflow_dispatch.' >&2; exit 1; }
! grep -q '^  workflow_run:' "$release" || { echo 'Release must not be triggered by workflow_run.' >&2; exit 1; }
! grep -q '^  push:' "$release" || { echo 'Release must not be triggered by push.' >&2; exit 1; }
grep -q 'environment: release' "$release" || { echo 'Release must use protected release environment.' >&2; exit 1; }

# Test coverage follows maintained Nextcloud releases and the newest available
# RC/Beta at run time. The protected manual release still records the explicit
# range that was proven by that successful run; it must not derive metadata itself.
for workflow in .github/workflows/nextcloud-integration.yml .github/workflows/kimai-saml-e2e.yml; do
    grep -q 'endoflife\.date/api/nextcloud.json' "$workflow" || { echo "Maintained Nextcloud discovery missing in $workflow" >&2; exit 1; }
    grep -q 'hub.docker.com/v2/repositories/library/nextcloud/tags' "$workflow" || { echo "Nextcloud RC/Beta discovery missing in $workflow" >&2; exit 1; }
done
! grep -Eq 'endoflife\.date|hub\.docker\.com' .github/workflows/release.yml || { echo 'Release workflow must not derive compatibility from external APIs.' >&2; exit 1; }

# Package scripts are allowlist based and must keep source-only trees out.
grep -q 'for entry in appinfo css img js l10n lib templates LICENSE' scripts/create-appstore-package.sh || { echo 'Runtime package allowlist missing.' >&2; exit 1; }
grep -q 'Missing post-signing appinfo/signature.json' scripts/verify-appstore-package.sh || { echo 'Post-signing signature check missing.' >&2; exit 1; }
echo 'Repository and release hygiene checks passed.'
