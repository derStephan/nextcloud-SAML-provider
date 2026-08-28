# Test Contract

> **DO NOT WEAKEN WITHOUT EXPLICIT HUMAN REVIEW.** This is a release-blocking specification, not documentation.

```text
=================================================================
TEST CONTRACT - DO NOT WEAKEN WITHOUT EXPLICIT REVIEW
=================================================================
0. Proof-first rule: every test exists to prove correct application behavior, never
   merely to become green. Do not invent, assume, or use private, internal, unofficial,
   undocumented, or version-specific APIs. Every OCP import and method call in production
   and integration code must be exactly declared in the machine-readable public API
   specification, reflected in every selected real Nextcloud target, and behavior-probed
   where it has an observable contract. Unknown imports or method calls must fail before
   functional testing. Unit doubles are permitted only as isolated fixtures; their public
   interface must be checked against the real runtime contract, not assumed.
1. Unit tests: discover every currently maintained PHP release at runtime and run
   the complete PHPUnit suite on every discovered version. Minimum PHP is 8.2;
   PHPUnit must remain compatible with all selected PHP versions (currently ^11.5).
   Enforce at least 80% production statement coverage for lib/ from Clover data as a
   hard gate: the coverage check must exit non-zero and fail the workflow below 80%.
   Every production OCP import and fully-qualified OCP method call, including templates,
   must be declared in the machine-readable inventory and reflection-probed on every target.
2. Nextcloud integration tests: discover every maintained Nextcloud major release
   at runtime from endoflife.date (minimum supported major: 33), then add the newest
   available official Apache RC or beta image from Docker Hub. Run every target with
   SQLite, MariaDB, and PostgreSQL. Do not replace this with a fixed 33/34 matrix.
3. Kimai browser E2E: run the complete real-browser SAML journey against every
   dynamically discovered Nextcloud target. Configure the IdP only through the real
   Nextcloud admin UI; do not use SQL fixtures or direct app-config writes.
4. E2E setup and evidence: disable Nextcloud firstrunwizard before browser login.
   Pull the current Kimai image dynamically and set `kimai.user.wizard: false`; do not
   automate Kimai's unrelated first-run setup UI. Request the protected Kimai homepage
   and prove that its final redirect target is a same-origin `/en/` application route
   returning HTTP 200, never a login, SAML, or wizard route. Kimai may redirect the
   homepage to a configured working page such as /en/timesheet/. Do not use visible
   labels, CSS classes, branding, or DOM structure as the authentication proof. For every target, retain
   machine-readable browser-flow traces for negative, positive, and tampered sessions;
   screenshots and bounded HTML snapshots for each terminal state; Kimai IdP settings;
   Nextcloud metadata and Kimai login-response headers; SSO request/response headers and
   bodies for both accepted NameID URNs and the rejected policy; and an E2E context file.
   On failure additionally retain docker-ps plus full Nextcloud, Kimai, and MariaDB logs.
   After a fully successful E2E journey, retain and upload one populated Nextcloud SAML
   admin screenshot per tested target. Artifact names must include the target and run.
5. E2E assertions: retain invalid-credential, signed positive SSO, and tampered
   SAMLResponse rejection flows. Verify Kimai metadata and login redirect through public
   HTTP endpoints before browser execution. Kimai 2.65+ requires connection.idp and
   connection.sp, an email mapping, and its /auth/saml/ base URL. Configure Kimai's
   unspecified NameID through the real admin UI, wait for successful save, reload, and
   verify persistence. Normalize certificates by removing only PEM markers/whitespace.
   Use stable login identifiers and durable rendered state. Invalid credentials must
   remain at Nextcloud and never call ACS. Kimai must require signed Response and
   Assertion, the positive flow must reach a protected Kimai page, and a browser-tampered
   signed response must not establish that session. Exercise SAML 1.1 and 2.0 unspecified
   NameIDPolicy URNs against the running SSO endpoint and prove an unsupported policy is
   HTTP 400. Prove `requireSignedRequests` against the live SSO endpoint separately for
   HTTP-Redirect and HTTP-POST: each binding must reject a well-formed unsigned request
   and continue a correctly OpenSSL-signed request to the real Nextcloud login redirect.
   After certificate generation, prove metadata through its public HTTP endpoint: 200,
   correct content type, well-formed XML, entity ID, SSO URL, and certificate. Missing
   IssueInstant and unavailable/expired IdP signing certificates must be rejected before
   response signing.
6. Toolchain floor: PHP >=8.2; PHPUnit ^11.5; Node.js 24 in the pinned Playwright
   image; npm 12.0.2; Playwright 1.62.1. Keep versions explicit and compatible.
7. CI hygiene and evidence: use actions/upload-artifact@v6 or later (Node 24 runtime).
   This rule applies to every existing and future test: suppress container-image layer
   progress and routine Docker/container lifecycle output in the CI log. Do not emit `docker logs` on success.
   When diagnostics are needed, print only genuine error,
   warning, failure, fatal, panic, critical, or exception entries; never suppress those
   entries. Retain full unfiltered container logs as failure artifacts, not as routine
   log output. Every E2E target must retain machine-readable negative, positive, and
   tampered browser traces; terminal screenshots and bounded HTML; Kimai IdP settings;
   metadata, login headers, NameID request/response artifacts, E2E context, and a
   populated Nextcloud SAML admin screenshot after success. On failure additionally
   retain docker-ps and full Nextcloud, Kimai, and MariaDB logs. Artifact names must
   include target and run. Upload Clover coverage to Codecov using the repository secret
   `CODECOV_TOKEN` and display the Codecov coverage badge in the README. Every successful
   complete Unit -> integration -> Kimai
   E2E chain for main, including normal pushes and scheduled compatibility checks,
   must create a patch release. Before any release work, fail clearly unless all three
   required release secrets are present: NEXTCLOUD_SIGNING_PRIVATE_KEY,
   NEXTCLOUD_SIGNING_CERTIFICATE, and NEXTCLOUD_APPSTORE_TOKEN. The App Store token
   must be used for the actual signed archive upload. Runtime discovery updates tested
   README/App Store compatibility metadata but must not suppress a release for a green
   main push.
=================================================================
```

## Agent operating rule

Before editing application code, tests, CI workflows, packaging, localization, or documentation that affects testing, read this contract and `AGENTS.md`. Do not weaken, bypass, or replace a requirement without explicit human approval and a corresponding update to the machine-enforced hygiene checks.
