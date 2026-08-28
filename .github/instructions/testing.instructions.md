---
applyTo: "tests/**,.github/workflows/**,composer.json,phpunit.xml.dist"
---

# Test and CI instructions

Read [`tests/TEST_CONTRACT.md`](../../tests/TEST_CONTRACT.md) and [`AGENTS.md`](../../AGENTS.md) before editing files covered by this instruction.

Never weaken a test solely to make CI pass. Preserve dynamic compatibility discovery, the blocking 80% production statement-coverage gate, Codecov upload through `CODECOV_TOKEN`, public OCP validation, real-browser SAML behavior proofs, signed response/assertion requirements, negative and tampered SAML flows, evidence artifacts, and Docker log hygiene.

Run the relevant syntax, contract, public-API, localization, and release-hygiene checks after changes.
