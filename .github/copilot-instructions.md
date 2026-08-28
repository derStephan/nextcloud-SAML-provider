# Repository instructions

Read [`AGENTS.md`](../AGENTS.md) before changing code, tests, CI workflows, packaging, localization, or documentation.

Before changing any test, workflow, coverage setting, or release behavior, read [`tests/TEST_CONTRACT.md`](../tests/TEST_CONTRACT.md). The Test Contract is mandatory and release-blocking. Do not weaken its assertions, compatibility discovery, coverage threshold, public-API rules, SAML security checks, evidence requirements, Codecov upload, or Docker log-hygiene rules without explicit human approval and corresponding machine-enforced checks.

Use real public behavior as test evidence. Do not replace browser/admin flows with SQL fixtures, direct app-config writes, undocumented APIs, or UI-dependent authentication proof. After relevant changes, run the repository's applicable hygiene checks.
