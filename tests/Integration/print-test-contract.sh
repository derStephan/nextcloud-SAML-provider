#!/usr/bin/env bash
# Emit the durable CI test contract. Keep this English text concise and actionable:
# it is deliberately part of every CI log so a later maintainer or AI can reconstruct
# the required test scope from an archived run.
set -euo pipefail
cat <<'CONTRACT'
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
2. Nextcloud integration tests: discover every maintained Nextcloud major release
   at runtime from endoflife.date (minimum supported major: 33), then add the newest
   available official Apache RC or beta image from Docker Hub. Run every target with
   SQLite, MariaDB, and PostgreSQL. Do not replace this with a fixed 33/34 matrix.
3. Kimai browser E2E: run the complete real-browser SAML journey against every
   dynamically discovered Nextcloud target. Configure the IdP only through the real
   Nextcloud admin UI; do not use SQL fixtures or direct app-config writes.
4. E2E setup: disable Nextcloud firstrunwizard before browser login. After a fully
   successful E2E journey, capture one populated Nextcloud SAML admin screenshot per
   tested Nextcloud target and upload it as an artifact.
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
   HTTP 400. After certificate generation, prove metadata through its public HTTP endpoint:
   200, correct content type, well-formed XML, entity ID, SSO URL, and certificate.
6. Toolchain floor: PHP >=8.2; PHPUnit ^11.5; Node.js 24 in the pinned Playwright
   image; npm 12.0.2; Playwright 1.62.1. Keep versions explicit and compatible.
7. CI hygiene: use actions/upload-artifact@v6 or later (Node 24 runtime). Suppress
   Docker layer progress for both integration and E2E image pulls. When Docker logs are
   emitted for diagnosis, suppress routine lifecycle chatter such as "completed",
   "verified", readiness and layer-status lines so it does not pollute the test log;
   never suppress Docker error, warning, failure, fatal, panic, or exception diagnostics.
   Every successful complete Unit -> integration -> Kimai
   E2E chain for main, including normal pushes and scheduled compatibility checks,
   must create a patch release. Before any release work, fail clearly unless all three
   required release secrets are present: NEXTCLOUD_SIGNING_PRIVATE_KEY,
   NEXTCLOUD_SIGNING_CERTIFICATE, and NEXTCLOUD_APPSTORE_TOKEN. The App Store token
   must be used for the actual signed archive upload. Runtime discovery updates tested
   README/App Store compatibility metadata but must not suppress a release for a green
   main push.
=================================================================
CONTRACT
