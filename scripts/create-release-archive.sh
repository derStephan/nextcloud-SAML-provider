#!/usr/bin/env bash
set -euo pipefail

version="${1:?Usage: scripts/create-release-archive.sh <version>}"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
parent="$(dirname "$root")"
archive="$parent/saml_provider-${version}.tar.gz"

# Run after code signing. The app directory must be named saml_provider.
tar -C "$parent" -czf "$archive" saml_provider
echo "Created $archive"
