#!/usr/bin/env bash
# Emit the canonical durable Test Contract in every CI log. This wrapper intentionally
# contains no contract text: tests/TEST_CONTRACT.md is the single source of truth.
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
contract="$root/tests/TEST_CONTRACT.md"
[[ -r "$contract" ]] || { echo "Missing canonical Test Contract: $contract" >&2; exit 1; }
cat "$contract"
