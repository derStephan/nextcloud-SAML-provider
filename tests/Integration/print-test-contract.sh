#!/usr/bin/env bash
# Emit the durable CI test contract. Keep this English text concise and actionable:
# it is deliberately part of every CI log so a later maintainer or AI can reconstruct
# the required test scope from an archived run.
set -euo pipefail
cat <<'CONTRACT'
=================================================================
TEST CONTRACT - DO NOT WEAKEN WITHOUT EXPLICIT REVIEW
=================================================================
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
5. E2E assertions: retain both invalid-credential and successful SSO flows. Verify
   Kimai metadata and its login redirect through public HTTP endpoints before browser
   flow execution. Kimai 2.65+ requires connection.idp and connection.sp (not a flat
   connection map), an email mapping, and its own /auth/saml/ base URL. Configure
   Kimai's unspecified NameID through the real admin UI, wait for the successful save
   response, reload, and verify its persisted value before running SSO. Normalize the
   generated certificate by removing only PEM markers and whitespace; support both
   single-line and multi-line widget output. Target Nextcloud login fields by stable
   id/name/autocomplete selectors rather than only input type. For invalid credentials,
   require that the browser remains at Nextcloud and no Kimai ACS request occurs. Use
   durable rendered state, never transient toast text, for UI waits.
6. Toolchain floor: PHP >=8.2; PHPUnit ^11.5; Node.js 24 in the pinned Playwright
   image; npm 12.0.2; Playwright 1.62.1. Keep versions explicit and compatible.
7. CI hygiene: use actions/upload-artifact@v6 or later (Node 24 runtime). Suppress
   Docker layer progress for both integration and E2E image pulls, but preserve
   failed-command diagnostics. Every successful complete Unit -> integration -> Kimai
   E2E chain for main, including normal pushes and scheduled compatibility checks,
   must create a patch release. Before any release work, fail clearly unless all
   three required release secrets are present: NEXTCLOUD_SIGNING_PRIVATE_KEY,
   NEXTCLOUD_SIGNING_CERTIFICATE, and NEXTCLOUD_APPSTORE_TOKEN. The App Store token
   must be used for the actual signed archive upload. Runtime discovery updates tested README/App
   Store compatibility metadata but must not suppress a release for a green main push.
=================================================================
CONTRACT
