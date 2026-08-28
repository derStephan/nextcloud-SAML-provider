# Agent instructions

## Mandatory reading order

Before changing code, tests, CI workflows, packaging, localization, or documentation, read:

1. [`tests/TEST_CONTRACT.md`](tests/TEST_CONTRACT.md)
2. [`CONTRIBUTING.md`](CONTRIBUTING.md)
3. the relevant source, test, workflow, and release-hygiene files.

## Non-negotiable test rules

- The Test Contract is a release-blocking specification, not documentation. Do not weaken, bypass, or replace any requirement without explicit human approval and a corresponding update to machine-enforced hygiene checks.
- Tests must prove real behavior. Do not make a test green by substituting private APIs, SQL fixtures, direct app-config writes, undocumented endpoints, or a weaker assertion.
- Preserve runtime compatibility discovery, the 80% production coverage gate, Codecov upload via `CODECOV_TOKEN`, public OCP checks, signed SAML response/assertion requirements, negative and tampered SAML flows, and mandatory evidence artifacts.
- Keep Kimai dynamically pulled. Do not automate unrelated Kimai onboarding UI; the test configuration disables it.
- Keep CI logs actionable: suppress routine Docker lifecycle and image-layer chatter, emit diagnostics only when needed, and retain complete failure logs as artifacts.
- Run the applicable contract, public-API, localization, syntax, and release-hygiene checks after relevant changes.

## Source of truth

`tests/TEST_CONTRACT.md` is canonical. `tests/Integration/print-test-contract.sh` must print that exact file in every CI run; do not duplicate or fork the contract text.
