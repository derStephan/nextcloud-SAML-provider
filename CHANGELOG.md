# Changelog

## 0.8.24

### Persisted Kimai NameID verification

- Wait for the real NameID update response, reload the Nextcloud administration page, and verify that Kimai's `unspecified` NameID format persisted before starting the browser SSO test.

## 0.8.23

### Complete App Store release-secret preflight

- Require and preflight the App Store token `NEXTCLOUD_APPSTORE_TOKEN` alongside the private signing key and public signing certificate.
- Upload the signed GitHub release archive to the Nextcloud App Store using the required API token after GitHub release creation.

## 0.8.22

### Restore releases for every green main push

- Release every complete successful Unit, database integration, and Kimai E2E chain on `main`, including normal pushes and scheduled compatibility checks; compatibility discovery updates metadata but no longer suppresses releases.
- Add an early release preflight that clearly fails when `NEXTCLOUD_SIGNING_PRIVATE_KEY` or `NEXTCLOUD_SIGNING_CERTIFICATE` is missing.

## 0.8.21

### Compatibility automation, Kimai setup, and CI usability

- Restore the GitHub download counter badge and remove the redundant release-version badge.
- Restore complete administrator-facing Kimai/Nextcloud setup instructions.
- Automatically publish a patch release after a fully successful compatibility test chain only when maintained PHP or Nextcloud compatibility changes; update README and App Store metadata from the tested result.
- Quiet Docker image pulls in the Nextcloud integration matrix and fix Kimai E2E NameID editing through the rendered detail row.

## 0.8.20

### Mandatory coverage gate and Kimai NameID negotiation

- Make the 80% `lib/` production-statement coverage threshold an explicitly named, blocking unit-test workflow gate and record the requirement in the durable test contract.
- Configure Kimai's `unspecified` NameID through the real Nextcloud admin UI and wait for the completed Nextcloud login redirect before browser login-field handling.

## 0.8.19

### Nextcloud login-flow resilience

- Target Nextcloud login fields through stable id, name, and autocomplete selectors instead of relying only on `input[type=password]`.
- Keep the invalid-login security assertion focused on the public contract: the browser must remain at Nextcloud and must not reach Kimai ACS; add concise input-state diagnostics when the login form is unavailable.

## 0.8.18

### E2E certificate normalization repair

- Normalize Nextcloud PEM certificate values by removing only PEM markers and whitespace, preserving certificate content from both single-line and multi-line admin-widget output.
- Add a regression test for both supported PEM field representations.

## 0.8.17

### Kimai 2.65 SAML compatibility

- Use Kimai's current nested `connection.idp` and `connection.sp` configuration schema, explicit SP endpoints, a Kimai-local SAML base URL, and the required `mail` mapping so Kimai registers its SAML routes.
- Pass the Nextcloud signing certificate in Kimai's recommended single-line body form without PEM markers.

## 0.8.16

### CI workflow repair

- Keep each GitHub Action `with:` map adjacent to its `uses:` step; print the durable test contract only after checkout configuration is complete.
- Add a release-hygiene guard against splitting `uses:` and `with:` blocks.

## 0.8.15

### CI and E2E resilience

- Restore dynamic maintained-Nextcloud plus newest RC/beta discovery for integration and Kimai browser E2E matrices.
- Print a durable English test contract in CI logs and suppress non-actionable Docker layer progress.
- Retry the strict Kimai SAML metadata contract until its public route is fully initialized.

## 0.8.5

### Security and audit remediation

### Follow-up remediation in the same unreleased 0.8.5 line

### Dead-code and API-surface cleanup

### Database and migration hardening

- Add a SQLite, MariaDB, and PostgreSQL Nextcloud integration matrix that runs the production mapper through real DBAL CRUD operations.
- Make fresh schemas portable: no TEXT defaults, a portable 255-character unique Entity ID, and an enabled-service index.
- Add an additive upgrade migration for the enabled-service index; legacy SLO columns are deliberately ignored rather than destructively dropped.

- Remove unused IdP organization update and certificate-import paths; certificates are generated and managed by the app.
- Remove unused SLO URL configuration from fresh schemas, entity serialization, the admin API, initial state, and UI. Existing database columns are ignored for backward compatibility.
- Remove unconsumed NameIDPolicy parsing, empty bootstrap callbacks, obsolete controller mapper injection, unused PNG assets, a dead CSS selector, and the unused release archive script.

- Bind decoded Redirect-binding parameters to the exact raw values covered by the SAML signature and reject duplicate signed query parameters.
- Make fresh schema definitions portable across SQLite, MariaDB/MySQL, and PostgreSQL by removing unsupported TEXT defaults and persisting non-null entity values.
- Validate SLO URLs and attribute-mapping object/value schema before persistence.
- Remove obsolete source-only release packaging code and correct documentation so fixture provisioning is not presented as mapper write-path coverage.

- Bound Redirect-binding DEFLATE output before allocation, suppress parser warnings for malformed unauthenticated XML, and require the direct SAML protocol Issuer plus required request fields.
- Remove the unvalidated SLO endpoint and its metadata advertisement; no external GET request can terminate a Nextcloud session through this app.
- Make IdP-initiated login a two-step flow: GET renders a same-origin confirmation page, while only a CSRF-protected POST creates an unsolicited assertion.
- Check every OpenSSL certificate-generation operation before persisting key material.
- Remove generated `build/` copies from the source distribution and keep packaging limited to explicit runtime files.
- Correct release and test documentation to distinguish tested behavior from future coverage goals.

## 0.8.0

### Audit readiness

- Complete a source, documentation, localization, packaging, workflow, and test-strategy preflight for external code audit.
- Stabilize UI translation message IDs and synchronize all browser and server-side locale catalogs with the English source keys.
- Add CI guards that reject missing or mismatched locale keys and block an empty, assertions-light, or incompletely mapped unit-test suite before PHPUnit runs.
- Retain layered verification: behavioral unit tests with cryptographic/XML assertions, real Nextcloud public-API contracts, and positive and negative browser SSO against Kimai.

## 0.7.39

- Align the README with the fail-closed App Store release workflow: required protected credentials, runtime-only archive allowlist, mandatory App Store registration, and release-loop protection are now documented.

## 0.7.38

- Harden App Store publication: build an explicit runtime-only package before integrity signing, excluding CI configuration, tests, documentation sources, release tooling, and development metadata.
- Make successful protected releases publish to the Nextcloud App Store through its API instead of silently falling back to a GitHub-only release when an opt-in variable is absent.
- Prevent release recursion: bot-authored release commits carry an explicit marker that blocks their later workflow-run chain from creating another release.

## 0.7.37

- Capture the complete Nextcloud SAML Provider admin settings content in E2E documentation screenshots by expanding Nextcloud's nested settings scroll container to its actual content height before the Playwright full-page capture.

## 0.7.36

- Disable Nextcloud's `firstrunwizard` app via OCC immediately after provisioning every ephemeral Kimai SAML E2E test instance, preventing welcome dialogs from obscuring the documentation screenshot.
- Simplify screenshot capture: it now verifies the real visible SAML Provider admin interface without browser-side dialog handling or DOM manipulation.

## 0.7.35

- Make the populated admin-page capture handle Nextcloud first-run and startup overlays: dismiss visible user controls, send Escape, and remove only large fixed overlays explicitly marked as loading/first-run/welcome/wizard before verifying the visible app page.

## 0.7.34

- Prevent obscured App Store/admin screenshots: the E2E capture now waits until the rendered SAML settings root owns the visible viewport centre and no large Nextcloud loading overlay remains before saving the image.

## 0.7.33

- Make release-time E2E screenshot validation independent of Pillow: validate the PNG signature, IHDR chunk, and non-zero dimensions with Python's standard library, which is available on GitHub-hosted runners.

## 0.7.32

- Fix the admin-settings capture after successful SAML E2E: retain the local Playwright work directory until the final browser screenshot is complete, then remove it.
- Remove the unused legacy `docs/appstore-screenshot.png`; it was not referenced by App Store metadata and is superseded by validated per-Nextcloud E2E screenshots.

## 0.7.31

- Remove the internal-only App Store release runbook from `docs/`; it was not part of the user-facing App Store listing.
- Make the release workflow write validated, target-specific E2E screenshot URLs into `appinfo/info.xml`, so current committed screenshots are displayed by the Nextcloud App Store instead of merely residing in `docs/`.

## 0.7.30

- Document screenshot import and validation as an explicit release-stage responsibility in the README, while keeping the E2E section focused on producing its evidence artifact.

## 0.7.29

- Upload one populated admin-settings screenshot artifact for every successful Nextcloud/Kimai E2E matrix job.
- Have the protected release workflow download and validate only screenshots from its exact triggering E2E workflow run, require complete matrix coverage, and commit them to `docs/` before versioning, signing, tagging, and publishing. Releases therefore carry current tested screenshots rather than a prior run's images.

## 0.7.28

- Name generated populated admin-page screenshots by their tested Nextcloud matrix target, for example `docs/admin-settings-e2e-nc34.png`, so parallel version runs never overwrite each other.
- Rewrite the App Store release guide for this SAML Provider's actual fail-closed CI, protected release workflow, opt-in App Store publication, and E2E screenshot review process.

## 0.7.27

- Capture the populated Nextcloud SAML Provider admin settings page in every successful Kimai browser E2E run. The capture verifies visible IdP settings and the registered Kimai Service Provider, writes `docs/admin-settings-e2e.png` in the test workspace, and includes it in the versioned diagnostics artifact.
- Update the README to document the public API preflight, per-version OCP contract, public Kimai HTTP preflight, negative and positive browser flows, artifact naming, and the review-safe screenshot publication model.

## 0.7.26

- Add a mandatory public-API preflight that scans application and test code for private Nextcloud APIs before any functional test runs. It reports the exact source location and replacement direction instead of allowing an opaque browser timeout.
- Run the documented `OCP` API contract against every selected real Nextcloud version before SAML provisioning and classify missing interfaces as an upstream compatibility finding.
- Add a Kimai public-HTTP preflight: validate its SAML metadata and confirm that its SAML login endpoint redirects to the configured IdP before Playwright starts.
- Remove unit-test fixtures for the unstable `OC::$server` nonce locator and remove Kimai private-database assertions from browser E2E coverage. The E2E test now verifies public, user-visible SAML outcomes only.
- Include app version, Nextcloud target, run ID, and retry attempt in diagnostic artifact names.

## 0.7.25

- Fix positive SSO rendering on Nextcloud 34: use the response-bound CSP nonce that Nextcloud supplies to templates instead of calling the internal `OC::$server->getContentSecurityPolicyNonceManager()` locator, which no longer exists in Nextcloud 34 and caused an HTTP 500.
- Name failed browser-E2E diagnostics with the app version, Nextcloud matrix target, GitHub run ID, and retry attempt. The artifact's downloaded ZIP filename is therefore unambiguous across runs.

## 0.7.24

- Preserve the exact Nextcloud internal exception log as a failed browser-E2E artifact before containers are cleaned up. This distinguishes CSP helper input validation from any other server-side error and records its stacktrace and source location.
- Keep the tested 0.7.23 `host:port` CSP behavior unchanged pending that server-side diagnostic; Nextcloud 33 accepts it and Nextcloud 34 must be fixed from the actual exception rather than inferred from the browser timeout.

## 0.7.23

- Restore cross-version CSP correctness for ACS endpoints on a non-default port: pass the validated `host:port` CSP host source (for example, `e2e-kimai:8001`) to Nextcloud, while still omitting scheme and path as required by Nextcloud 34.
- The browser test now correctly identifies the issue as CSP enforcement rather than a JavaScript or fallback-click timeout: neither automatic submission nor clicking a form button can bypass a `form-action` policy.

## 0.7.22

- Fix Nextcloud 34 positive SSO rendering: pass only the parsed ACS host to `addAllowedFormActionDomain()`. Nextcloud 34 rejects a full scheme-and-port origin in that domain-only API and otherwise raises an HTTP 500 before the SAML response form can be rendered.
- Keep the browser E2E flow unchanged: it continues to prove both rejected invalid IdP credentials and successful positive SSO.

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

## [0.8.29] - 2026-08-28

- Verify complete persisted Kimai service configuration after an admin-UI reload before live SAML protocol probes.
- Retain SSO probe XML, response headers, and response bodies for actionable HTTP 400 diagnostics without weakening any assertion.


## [0.8.28] - 2026-08-28

- Remove unsupported `IDBConnection::getPrefix()` and the non-public, destructive schema-index probe from integration tests.
- Make integration CLI failures propagate their real non-zero exit status; a failed contract can no longer reach the success marker.
- Prove both real migration executions by rerunning production mapper persistence behavior after them.


## [0.8.27] - 2026-08-28

- Enforce an exact, generated OCP import/method inventory and runtime API preflight for every supported Nextcloud target.
- Add a live unsupported NameIDPolicy rejection test and replace the undocumented migration-result fetch path with the documented OCP cursor API.
- Record the proof-first test contract as the binding CI contract.


## [0.8.26] - 2026-08-28

- Make the Kimai interoperability test require signed SAML Responses and Assertions, verify a protected authenticated Kimai page, and reject a browser-tampered SAML response.
- Replace source-marker claims with live HTTP protocol checks for both supported unspecified NameID formats and generated IdP metadata.
- Complete the public OCP API contract and make upgrade-index probing restore the schema after each verification.


## [0.8.25] - 2026-08-28

- Repair the upgrade integration contract to use verified public OCP database operations only and fail direct CLI contracts with a non-zero exit status.
- Accept both SAML 1.1 and SAML 2.0 `unspecified` NameID URNs for Kimai interoperability.


## [0.8.14] - 2026-08-28

### Fixed

- Restore discovery of maintained Nextcloud releases and the newest available RC/Beta for integration and Kimai browser tests.
- Upgrade artifact uploads to `actions/upload-artifact@v6`, which runs on Node.js 24.
- Wait for the durable generated certificate field in browser setup instead of a transient translated toast notification.


## [0.8.13] - 2026-08-28

### Fixed

- Bootstrap the app namespace explicitly for standalone real-Nextcloud integration contracts, so DBAL persistence and signed-request contracts execute the production mapper and entity classes.


## [0.8.12] - 2026-08-28

### Fixed

- Make the release-hygiene check portable to standard GitHub runners by using `grep` instead of a `ripgrep` dependency.

### Documentation

- Add the Kimai browser end-to-end badge, current administrator screenshots, and a shorter setup-first README.


## [0.8.11] - 2026-08-28

### Fixed

- Align OCP request and app-configuration contracts with the real Nextcloud public API.
- Keep unavoidable SAML Redirect raw-query access isolated in RawQueryService after confirming that IRequest exposes decoded parameters only.


## [0.8.10] - 2026-08-28

### Fixed

- Make the Nextcloud URL-generator test fixture preserve route query parameters, so the SSO login-return URL is tested as the application passes it to Nextcloud.


## [0.8.9] - 2026-08-28

### Fixed

- Exercise SAML controller failure, redirect, session-consistency, and unknown-service paths with functional unit scenarios.
- Declare the raw-query adapter as an explicitly used PHPUnit class, eliminating risky-test diagnostics without reducing strict coverage metadata.


## [0.8.8] - 2026-08-28

### Fixed

- Move the unit-test toolchain to PHPUnit 11.5+ on PHP 8.2+ with Composer audit fully enabled and no ignored advisories.
- Remove marker-based test-quality gates that inspected test source text rather than application behavior.
- Keep quality evidence focused on real production contracts: DBAL mapper CRUD, actual Nextcloud migrations, public SSO endpoints, and browser SSO with Kimai.


## [0.8.7] - 2026-08-28

### Fixed

- Repair unit-test dependency injection for the raw-query adapter.
- Accept the valid empty attribute-mapping object `{}` while continuing to reject JSON arrays.
- Update AuthnRequest unit fixtures to include the required SAML 2.0 Version attribute.


## [0.8.6] - 2026-08-28

### Fixed

- Hardened SAML HTTP-Redirect raw-query handling through a dedicated request adapter instead of direct PHP superglobal access.
- Derived persistent NameIDs with HMAC-SHA256 and an installation-specific sensitive secret.
- Restored the dynamic CI matrix for currently supported PHP versions.
- Restored PHPUnit `^10.5` resolution and scoped the test-only Composer advisory exception to `PKSA-z3gr-8qht-p93v`.
- Reduced shipped translations to complete English and German catalogs and strengthened localization and behavioral-contract checks.


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
