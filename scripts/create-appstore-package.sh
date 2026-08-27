#!/usr/bin/env bash
# Build a minimal, deterministic Nextcloud App Store staging directory.
# Run before `occ integrity:sign-app`; signing writes appinfo/signature.json.
set -euo pipefail

output_root="${1:?Usage: scripts/create-appstore-package.sh <output-directory>}"
app_id='saml_provider'
stage="$output_root/$app_id"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

rm -rf "$stage"
mkdir -p "$stage"

# Only application runtime code, metadata, front-end assets, translations, and
# the license belong in the installed App Store package. Development tooling,
# tests, CI definitions, documentation sources, and release scripts are kept
# in the repository but never delivered to Nextcloud instances.
for entry in appinfo css img js l10n lib templates LICENSE; do
    [[ -e "$root/$entry" ]] || { echo "Required runtime entry is missing: $entry" >&2; exit 1; }
    cp -a "$root/$entry" "$stage/$entry"
done

# Fail closed if a later edit accidentally broadens the delivery set.
for forbidden in .github tests docs scripts build .git README.md CHANGELOG.md CONTRIBUTING.md SECURITY.md phpunit.xml.dist composer.json composer.lock; do
    [[ ! -e "$stage/$forbidden" ]] || { echo "Non-runtime entry in App Store staging directory: $forbidden" >&2; exit 1; }
done

[[ -f "$stage/appinfo/info.xml" ]] || { echo 'Missing appinfo/info.xml' >&2; exit 1; }
[[ -f "$stage/appinfo/routes.php" ]] || { echo 'Missing appinfo/routes.php' >&2; exit 1; }
echo "Prepared minimal App Store staging directory: $stage"
