#!/usr/bin/env bash
# Verify the post-signing staging directory immediately before it is archived.
set -euo pipefail

stage="${1:?Usage: scripts/verify-appstore-package.sh <staged-app-directory>}"
[[ -d "$stage" ]] || { echo "Staging directory does not exist: $stage" >&2; exit 1; }

allowed=(LICENSE appinfo css img js l10n lib templates)
mapfile -t actual < <(find "$stage" -mindepth 1 -maxdepth 1 -printf '%f\n' | sort)
expected="$(printf '%s\n' "${allowed[@]}" | sort)"
observed="$(printf '%s\n' "${actual[@]}")"
[[ "$observed" == "$expected" ]] || {
    echo 'App Store staging directory contains unexpected top-level entries.' >&2
    printf 'Expected:\n%s\nObserved:\n%s\n' "$expected" "$observed" >&2
    exit 1
}

[[ -f "$stage/appinfo/signature.json" ]] || { echo 'Missing post-signing appinfo/signature.json' >&2; exit 1; }
for forbidden in .github tests docs scripts build .git README.md CHANGELOG.md CONTRIBUTING.md SECURITY.md phpunit.xml.dist composer.json composer.lock; do
    [[ ! -e "$stage/$forbidden" ]] || { echo "Non-runtime entry in signed package: $forbidden" >&2; exit 1; }
done

echo "Verified signed runtime-only App Store package: $stage"
