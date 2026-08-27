# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project uses [Semantic Versioning](https://semver.org/).

## 0.7.21

- Add a negative browser SSO scenario: deliberately invalid Nextcloud credentials must remain on the IdP login page, must not invoke Kimai ACS, and must not create a Kimai SAML user.
- Run the negative scenario in an isolated browser session before the positive end-to-end SSO proof, retaining browser-flow artifacts for both paths.

## 0.7.20

- Remove the artificial empty POST probe to Kimai's ACS, which always generated a misleading “SAML Response not found” error in container diagnostics.
- Require and record the real browser ACS response as a successful HTTP redirect before accepting the authenticated Kimai destination.
- Keep the independent database assertion that exactly one expected SAML-authenticated Kimai user was imported.

## 0.7.19

- Fix the browser E2E race after Nextcloud login: accept either a visible Nextcloud SAML POST form or an already completed Kimai session caused by the form's normal auto-submit.
- Retain the visible-form fallback click only when required, while accepting Kimai's legitimate first-run wizard as authenticated SSO completion.

## 0.7.18

- Remove the fixed-value coverage-gate test fixture; production coverage remains enforced by the gate itself without encoding one historical percentage.
- Fix the TemplateResponse unit-test double to retain the attached content-security policy, allowing the meaningful ACS-origin CSP regression test to inspect the same response state as production.

## 0.7.17

- Fix the coverage gate to aggregate Clover statement metrics from the production `lib/` files themselves, matching PHPUnit's reported production line coverage instead of relying on a version-dependent project aggregate.
- Add a regression test for the 545/637 (85.56%) coverage case and improve gate output with counted files and statements.

## 0.7.16

- Preserve the complete registered ACS origin — scheme, host, and effective port — in Nextcloud's `form-action` CSP allowance. This permits SAML POST binding to a non-default-port ACS such as `http://e2e-kimai:8001` instead of allowing only the host's default port.
- Add a regression test for an ACS running on port 8001.
- Record browser console messages and page errors in the SSO-flow artifact, including any future CSP violation.

## 0.7.15

- After Nextcloud renders its own signed SAML POST page, wait for the normal JavaScript auto-submit and then, only when that rendered form remains visible, activate its existing Continue button through Chromium. The test never reads, rebuilds, or manually posts SAML values.
- Preserve a pre-handoff screenshot and HTML capture for the SAML POST page.
- Specify the MariaDB server version as `11.4.0-MariaDB`, matching Doctrine's expected server-reported format and removing its DBAL version-detection deprecation warning.

## 0.7.14

- Standardize the Kimai E2E matrix-discovery step with the Nextcloud integration workflow: matching labels, explicit stable/RC-beta outcomes, and the discovered matrix in the log.
- Bootstrap and integrity-verify npm 12.0.2 directly with Node 24, without executing the image-provided npm 11 CLI or suppressing its update notice.
- Add structured browser-flow diagnostics with navigation and relevant response events, plus post-login and failure screenshots and HTML captures.

## 0.7.13

- Replace the npm 12 bootstrap global install with a local installation under `/work/npm-tool`; npm no longer attempts to rename the image-owned `/usr/lib/node_modules/npm` directory.
- Continue verifying npm 12.0.2 and use only its local binary for the matching Playwright SDK installation.

## 0.7.12

- Install npm 12.0.2 into a writable ephemeral prefix instead of attempting to replace the image-owned system npm under `/usr/lib`, eliminating the permission failure while retaining unprivileged browser execution.
- Use that verified npm 12.0.2 binary for the matching Playwright 1.62.1 SDK installation and import check.

## 0.7.11

- Align the runtime-installed Playwright Node SDK with the Playwright 1.62.1 Docker image, fixing the Chromium executable-version mismatch caused by the previously retained 1.54.0 package.
- Upgrade the temporary test-container npm CLI to 12.0.2 and assert its installed version before resolving the Playwright 1.62.1 SDK.
- Log the matched SDK/browser version immediately after the actual browser-test start marker.

## 0.7.10

- Fix the Nextcloud login return target to be an origin-safe relative SSO route, preventing the invalid `/http://e2e-nextcloud/...` return path.
- Add a controller regression assertion for relative login return targets.
- Upgrade the Playwright image to 1.62.1 and use `actions/upload-artifact@v5` for the Node 24 runtime.

## 0.7.9

- Place Kimai SAML `baseurl` under the documented `connection` section and enable test-only Kimai SAML debug output.
- Replace opaque Kimai metadata failures with explicit HTTP-status, response-body, and container-log diagnostics.
- Create E2E diagnostic artifacts before Kimai metadata validation and collect container logs into the uploaded artifact on every failure.

## 0.7.8

- Configure Kimai SAML `baseurl` explicitly as the complete E2E `/auth/saml/` URL, preventing the SAML adapter from resolving an absolute Nextcloud SSO endpoint as a relative path and duplicating the IdP host.
- Fail early with a focused browser diagnostic if Kimai ever constructs a duplicated absolute IdP redirect again.

## 0.7.7

- Install the exact pinned Playwright Node package in a temporary browser work directory with browser download disabled; the official Playwright Docker image supplies the matching browser binaries and operating-system dependencies.
- Start the browser trace only after the package preparation phase, then execute the test from that work directory so Node resolves the installed package reliably.

## 0.7.6

- Load the Playwright container image before the browser-test trace marker, so the marker represents the actual start of the Chromium SSO flow.
- Mount and execute the browser test under the official Playwright image dependency root, allowing Node.js to resolve the image-bundled `playwright` package without project-local installation.

## 0.7.5

- Run the Kimai SAML end-to-end gate across the same dynamically discovered Nextcloud stable and explicit RC/beta Docker-image matrix as the integration workflow.
- Drive the complete Kimai-initiated SAML login with headless Chromium and verify the imported Kimai SAML user, rather than parsing Nextcloud login HTML, request tokens, or form actions.
- Preserve failure diagnostics with per-matrix browser screenshots and container logs, while keeping the release pipeline fail-closed.
- Pull the Playwright image before the browser-test trace marker and execute the mounted browser module below the image dependency root so Node resolves its bundled package.

## 0.7.4

- Keep the mandatory Kimai release gate focused on stable real-container SAML interoperability: migration, IdP/SP metadata, configured ACS, and endpoint availability.
- Remove the UI-coupled browser-login experiment from the release gate; Nextcloud login template details are not an app compatibility contract and must not block releases when they change across supported server versions.
- Clarify the README distinction between the dynamic real-Nextcloud API compatibility matrix, the mandatory Kimai interoperability gate, and a future non-blocking browser-login canary.

## 0.7.3

- Make the Kimai E2E login submit to the action and username field discovered from the actual Nextcloud login form, rather than assuming the displayed login URL or a fixed field name.
- Include the parsed credential-form details in failed-login diagnostics.

## 0.7.2

- Add clear start and successful-end log markers around the actual Kimai SSO protocol trace.
- Submit Nextcloud's login request token in both the `requesttoken` header and form field, matching the current login page's `data-requesttoken` contract and preserving compatibility with form-based validation.

## 0.7.1

- Support Nextcloud's current `data-requesttoken` login-page attribute in the Kimai E2E flow, while retaining compatibility with hidden-input token templates.
- Fail with focused diagnostics if Nextcloud redirects a login submission back to `/login` instead of the pending SSO request.

## 0.7.0

- Replace brittle shell parsing of the generated SAML POST form in the Kimai E2E test with a dedicated HTML parser.
- Record the SSO return URL, headers, parsed form data, and a capped response excerpt when Nextcloud does not return the expected SAML POST form.

## 0.6.7

- Make the Kimai E2E flow independent of a particular Nextcloud login-template request-token field: send the token when present, then validate the actual login submission result.
- Add actionable diagnostics for a failed Nextcloud login submission, including page and submission response headers plus capped response excerpts.

## 0.6.6

- Make the Kimai E2E login-token extraction robust to either HTML attribute order and print a capped login response diagnostic when the token is absent.
- Preserve E2E containers on test failure until the GitHub Actions diagnostics step has collected their logs; keep automatic cleanup after successful runs.

## 0.6.5

- Clarify the README test architecture: local unit-test doubles are behavioral fixtures only; the dynamically discovered real Nextcloud matrix is the API-compatibility authority, and the Kimai stage is a full SSO test rather than wiring-only validation.

## 0.6.4

- Fix full Kimai SSO test workspace writes: run the temporary HTTP client with the GitHub runner's numeric UID/GID so its shared cookie jar, headers, and response files are writable on the bind-mounted E2E directory.

## 0.6.3

- Correct the Nextcloud API contract test for dynamic `Entity` accessors: verify `ServiceProvider::setId()`/`getId()` behavior in each real matrix container through Nextcloud's `Entity::__call()` mechanism instead of requiring a non-declared base-class method.
- Align the unit-test Entity double with that dynamic accessor behavior, preventing it from exposing a wider fake Entity API than production.

## 0.6.2

- Fix the full Kimai E2E setup to use the positional value argument required by `occ user:setting`.
- Add a real Nextcloud public-API contract test to every dynamically selected integration-matrix image, covering supported stable releases and available RC/beta versions so local unit-test doubles cannot mask OCP API drift.

## 0.6.1

- Harden RelayState handling: absolute redirect targets now require an exact allowed origin, including scheme and effective port, rather than only a matching hostname.
- Reject browser-ambiguous backslash paths and control characters in RelayState, with regression tests for HTTPS downgrade and alternate-port redirects.

## 0.6.0

- Upgrade the mandatory Kimai Docker workflow to a full SP-initiated SSO regression test: AuthnRequest, Nextcloud login, signed POST binding, Kimai ACS validation, and MariaDB-backed SAML-user provisioning.

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
