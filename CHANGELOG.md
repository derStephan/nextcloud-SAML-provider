# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project uses [Semantic Versioning](https://semver.org/).

## 0.5.8

- Fix SAML SSO requests on Nextcloud 33 by removing the unsupported `IRequest::getServerParam()` call.
- Preserve the raw redirect query string through `$_SERVER['QUERY_STRING']` for standards-compliant SAML Redirect-binding signature validation, and align the unit-test request double with the real Nextcloud public API.

## 0.5.7

- Restore the existing shield icon used inside Nextcloud.
- Add the generated SAML identity-network illustration as `docs/appstore-screenshot.png` for App Store/documentation presentation.

## 0.5.6

- Replace the generic shield/checkmark app icon with an original SAML identity-network icon and provide a contrast-optimized dark-mode variant.

## 0.5.5

- Fix the persistent-NameID unit test to count only the XMLDSig `Signature` element instead of similarly named child elements.

## 0.5.4

- Expand the README with a transparent, precise description of the full layered test strategy, fail-closed workflow order, Kimai E2E wiring coverage and boundaries, signed GitHub releases, and opt-in App Store publication.

## 0.5.3

- Add meaningful regression coverage for malformed Redirect requests, persistent NameIDs, unsigned responses, custom attribute mappings, AuthnRequest signature-policy failures, anonymous SSO redirects, and inconsistent logged-in sessions.
- Retain the complete `lib/` scope, the 80% required gate, and target a higher practical coverage margin.

## 0.5.2

- Add authenticated SP-initiated SSO controller coverage, including the auto-post response path.
- Add direct coverage for the administration section metadata and icon.

## 0.5.1

- Fix the SAML controller PHPUnit test syntax by rewriting the expanded test class with correctly scoped methods and braces.

## 0.5.0

- Expand direct controller coverage for successful metadata, IdP-initiated SSO, registered-SP logout, duplicate SP prevention, valid updates, organization persistence, and certificate generation.
- Keep the complete `lib/` coverage scope and 80% gate unchanged.

## 0.4.1

- Fix the `ServiceProvider` import in the SAML controller PHPUnit coverage annotation.
- Add direct runtime coverage for the administration settings form and its published initial state.

## 0.4.0

- Declare PHPUnit coverage targets and used dependencies for controller, settings, launcher, and application tests so executed production code is included in the full `lib/` coverage report.
- Keep strict PHPUnit coverage metadata and the 80% whole-library gate enabled.

## 0.3.3

- Enforce a fail-closed sequential GitHub Actions pipeline: Unit tests → Nextcloud integration → Kimai SAML E2E → release.
- Run each downstream workflow only after its direct predecessor succeeds and check out the exact tested commit SHA.

## 0.3.2

- Use Kimai's container port 8001 consistently in the Docker-network E2E readiness, metadata, Entity ID, and ACS checks.

## 0.3.1

- Correct the PHPUnit OCP HTTP test double namespace so controller status constants resolve as they do in Nextcloud.
- Make the unknown-SP controller test deterministic and add coverage attributes to controller-harness tests.

## 0.3.0

- Fix Kimai E2E readiness detection: accept a reachable 3xx response as healthy because Kimai redirects anonymous root requests to its login page.
- Keep SAML metadata and ACS endpoint checks strict after the readiness phase.

## 0.2.7

- Add a PHPUnit OCP test harness and direct controller tests for Settings, launcher and SAML controller security branches.
- Keep the complete `lib/` coverage scope and 80% coverage gate unchanged; this change is a test-harness expansion, not a source exclusion.

## 0.2.6

- Trust Kimai loopback hosts in the ephemeral Docker E2E configuration so Kimai’s own container health request is not rejected as an untrusted host.
- Add a migration contract test for the complete Service Provider persistence schema; the E2E workflow continues to validate the table against a real fresh SQLite installation.

## 0.2.5

- Configure the internal `e2e-nextcloud` Docker hostname as a trusted domain in the ephemeral Kimai E2E installation, preventing Nextcloud’s expected HTTP 400 host rejection from masking metadata checks.

## 0.2.4

- Explicitly reject DTD-bearing AuthnRequests before XML parsing, including internal entity declarations.
- Correct the signed-response regression test to assert that no nested XML declaration is emitted by DOM root-element serialization.

## 0.2.3

- Restore the 80% coverage gate across the complete `lib/` directory without source exclusions.
- Fix the Kimai E2E migration assertion by inspecting the fresh Nextcloud SQLite database through PDO instead of using a non-portable `occ db:query` command.
- Add AuthnRequest size-limit and DTD/entity rejection regression tests.

## 0.2.2

- Fix DOM-based XMLDSig serialization: signed nested assertions no longer inject a second XML declaration into the enclosing SAML Response.
- Add a regression assertion that the generated SAML Response is well-formed XML.
- Set the whole-`lib/` coverage gate to a transparent 50% baseline while controller and real database integration coverage is added.

## 0.2.1

- Add a separate Docker-based Kimai SAML integration workflow that verifies a fresh Nextcloud migration, IdP metadata, Kimai SP metadata, and the Kimai ACS endpoint.
- Keep this end-to-end workflow isolated from all release and App Store publication paths.

## 0.2.0

### Security and reliability
- Add a Nextcloud migration for the Service Provider database table; remove obsolete `database.xml`.
- Fix POST-binding XMLDSig verification, disable SHA-1 algorithms, reject DTD/entity expansion, and cap decoded AuthnRequest size at 1 MiB.
- Insert XML signatures with DOM operations rather than regex replacement.
- Validate the complete prospective Service Provider configuration on every update.

### Known limitation
- SAML Response replay prevention relies on the Service Provider tracking `InResponseTo` and assertion IDs. The IdP does not persist a replay cache yet.

## 0.1.31

- Add AI-assisted draft locale catalogues for 18 additional widely spoken languages; English and German remain the baseline catalogues.
- Add translation-review guidance to the README.

## 0.1.30

- Simplify the README development-transparency attribution to GPT 5.6 Terra by OpenAI.

## 0.1.29

- Name GPT 5.6 Terra by OpenAI, hosted via Requesty, in the README development-transparency note.

## 0.1.28

- Add a README transparency note describing CERTANIA AI Workspace assistance and human maintainer accountability.

## 0.1.27

- Pass the `CODECOV_TOKEN` repository secret explicitly to the Codecov upload action so coverage reports can be associated with the repository and the badge can resolve.

## 0.1.26

- Upgrade the Codecov action to v6 to remove the Node.js 20 `actions/github-script` runtime warning.
- Run Nextcloud app signing through `/usr/src/nextcloud/occ` with the Docker entrypoint bypassed, so signing works before the image initializes its `/var/www/html` data volume.

## 0.1.25

- Add a GitHub Release-downloads badge to the README.
- Make Nextcloud App Store publication opt-in: the release pipeline now uploads only when the GitHub repository variable `PUBLISH_TO_APPSTORE` is set to `true`. GitHub release creation and code-signing validation continue in dry-run mode.

## [Unreleased]

### Added

- Documentation and repository files required for public App Store submission.
- Automated unit, coverage, and Nextcloud integration test workflows.

## [0.1.22]

### Added

- Public-release metadata and App Store description.

### Fixed

- SAML response persistence, CSP handling, XML signature generation, and XML signature validation compatibility issues.

[Unreleased]: https://github.com/derStephan/nextcloud-SAML-provider/compare/v0.1.22...HEAD
[0.1.22]: https://github.com/derStephan/nextcloud-SAML-provider/releases/tag/v0.1.22
