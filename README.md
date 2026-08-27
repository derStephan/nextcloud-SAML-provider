# SAML Provider for Nextcloud

[![Unit tests](https://github.com/derStephan/nextcloud-SAML-provider/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/derStephan/nextcloud-SAML-provider/actions/workflows/tests.yml)
[![Nextcloud integration tests](https://github.com/derStephan/nextcloud-SAML-provider/actions/workflows/nextcloud-integration.yml/badge.svg?branch=main)](https://github.com/derStephan/nextcloud-SAML-provider/actions/workflows/nextcloud-integration.yml)
[![Code coverage](https://codecov.io/gh/derStephan/nextcloud-SAML-provider/graph/badge.svg?branch=main)](https://codecov.io/gh/derStephan/nextcloud-SAML-provider)
[![GitHub release downloads](https://img.shields.io/github/downloads/derStephan/nextcloud-SAML-provider/total)](https://github.com/derStephan/nextcloud-SAML-provider/releases)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

<!-- NEXTCLOUD_COMPATIBILITY:START -->
**Tested Nextcloud compatibility:** 33 or later
<!-- NEXTCLOUD_COMPATIBILITY:END -->

Turn Nextcloud into a **SAML 2.0 Identity Provider (IdP)**. External applications acting as Service Providers (SPs) can authenticate users against their Nextcloud accounts using SAML single sign-on (SSO).

> This project implements the SAML 2.0 Web Browser SSO profile. Review the [Security notes](#security-notes) before using it in production.

## Features

- SP-initiated SSO using HTTP-Redirect or HTTP-POST AuthnRequests
- IdP-initiated SSO from the app launcher
- Signed SAML Responses and Assertions using RSA-SHA256 and enveloped XMLDSig
- IdP metadata endpoint
- Per-service configuration: Entity ID, ACS URL, optional SLO URL, NameID format, attributes, and SP certificate
- Optional validation of signed AuthnRequests for each service
- Nextcloud administration interface and user-facing launcher

## Development transparency

This project was developed with assistance from **GPT 5.6 Terra by OpenAI**, including support for implementation, test coverage, CI/CD configuration, documentation, and release-process improvements. Human maintainers remain responsible for technical review, security assessment, testing, deployment decisions, and every published release.

## Translations

The app includes English and German plus AI-assisted draft catalogues for 18 additional widely spoken languages: Arabic, Bengali, Chinese (Simplified), Spanish, French, Hindi, Indonesian, Italian, Japanese, Korean, Polish, Portuguese (Brazil), Russian, Thai, Turkish, Ukrainian, Urdu, and Vietnamese. Please review translations in your native language before relying on them in production, especially security-related wording. English remains the fallback for strings awaiting community review.

All browser (`.json`/`.js`) and server-rendered (`.php`) locale catalogs are checked in CI against the English source key sets. The check prevents a UI change from shipping with a missing, stale, or mismatched message key; it does not claim linguistic review of AI-assisted draft wording.

## Requirements

- Nextcloud versions declared in `appinfo/info.xml` and verified by the integration workflow
- PHP with the OpenSSL and DOM extensions enabled
- HTTPS for Nextcloud and every connected service
- An administrator account

## Installation

1. Extract or copy the application directory as `saml_provider` into a directory configured in Nextcloud's `apps_paths`, commonly `custom_apps/`.
2. Ensure that the web-server user can read the application files.
3. From the Nextcloud installation directory, enable the app:

   ```bash
   sudo -u www-data php occ app:enable saml_provider
   ```

4. Restart PHP-FPM if applicable to clear OPcache.

Open **Administration settings → SAML Provider** afterwards.

## Initial IdP setup

1. Open **Administration settings → SAML Provider** as a Nextcloud administrator.
2. Select **Generate certificate**.
   - The app creates a self-signed RSA-4096 signing certificate and private key.
   - Keep the private key inside Nextcloud. Share only the public certificate with Service Providers.
3. Copy the metadata URL or note the endpoints below.
4. Create a connected service by entering its display name, SAML Entity ID, and ACS URL.
5. Configure the external service with the Nextcloud IdP details and public certificate.
6. Test SP-initiated login from the external service before disabling any local login method.

## IdP endpoints

Replace `cloud.example.com` with the public hostname of the Nextcloud instance.

| Purpose | URL |
| --- | --- |
| Metadata | `https://cloud.example.com/apps/saml_provider/saml/metadata` |
| SSO endpoint | `https://cloud.example.com/apps/saml_provider/saml/sso` |
| SLO endpoint | `https://cloud.example.com/apps/saml_provider/saml/slo` |
| IdP-initiated login | `https://cloud.example.com/apps/saml_provider/saml/login/{service-provider-id}` |
| User launcher | `https://cloud.example.com/apps/saml_provider/` |

The metadata endpoint is the preferred onboarding method when the Service Provider supports IdP metadata import.

## Connected service settings

Each connected service needs the following values:

| Setting | Meaning |
| --- | --- |
| **Name** | A human-readable name shown to administrators and users. |
| **Entity ID** | The Service Provider's unique SAML identifier. |
| **ACS URL** | The Assertion Consumer Service endpoint that receives the SAML Response. |
| **Logout URL** | Optional. Enter it only when the service provides an SLO endpoint. |
| **NameID format** | How the user is identified. Email address is suitable for most services. |
| **Attribute mapping** | Optional JSON mapping for additional attributes. |
| **Service Provider certificate** | Required only when the service signs AuthnRequests. |
| **Require signed AuthnRequests** | Enables signature validation for AuthnRequests from that service. |

The app always sends these attributes when the Nextcloud user profile contains a value:

- `uid` — Nextcloud user ID
- `displayName` — Nextcloud display name
- `mail` — Nextcloud email address

Additional attributes can be mapped with JSON. For example:

```json
{"username":"uid","email":"mail","name":"displayName"}
```

## Kimai example

This example connects Kimai at `https://kimai.example.com` to Nextcloud at `https://cloud.example.com`.

### Nextcloud connected service

In **Administration settings → SAML Provider → Connect a new service**, create:

| Field | Value |
| --- | --- |
| Name | `Kimai` |
| Entity ID | `https://kimai.example.com/auth/saml/metadata` |
| ACS URL | `https://kimai.example.com/auth/saml/acs` |

Use the default **Email address** NameID format. Leave **Require signed AuthnRequests** disabled for the standard Kimai configuration below.

### Kimai `local.yaml`

Add the following to Kimai's `config/packages/local.yaml`. Replace the certificate placeholder with the **single-line** Nextcloud signing certificate: remove the PEM `BEGIN CERTIFICATE` / `END CERTIFICATE` lines and all line breaks.

```yaml
kimai:
    saml:
        provider: nextcloud
        activate: true
        title: 'Login with Nextcloud'
        mapping:
            - { saml: $mail, kimai: email }
            - { saml: $displayName, kimai: alias }
            - { saml: 'SAML user', kimai: title }
        connection:
            idp:
                entityId: 'https://cloud.example.com/apps/saml_provider/saml/metadata'
                singleSignOnService:
                    url: 'https://cloud.example.com/apps/saml_provider/saml/sso'
                    binding: 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect'
                singleLogoutService:
                    url: 'https://cloud.example.com/apps/saml_provider/saml/slo'
                    binding: 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect'
                x509cert: 'PASTE_NEXTCLOUD_CERTIFICATE_AS_ONE_BASE64_LINE_HERE'
            sp:
                entityId: 'https://kimai.example.com/auth/saml/metadata'
                assertionConsumerService:
                    url: 'https://kimai.example.com/auth/saml/acs'
                    binding: 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST'
                singleLogoutService:
                    url: 'https://kimai.example.com/auth/saml/logout'
                    binding: 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect'
            strict: true
            debug: false
            security:
                wantMessagesSigned: true
                wantAssertionsSigned: true
                wantNameIdEncrypted: false
                nameIdEncrypted: false
                authnRequestsSigned: false
                logoutRequestSigned: false
                logoutResponseSigned: false
                requestedAuthnContext: true
                signMetadata: false
                wantXMLValidation: true
                signatureAlgorithm: 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256'
                digestAlgorithm: 'http://www.w3.org/2001/04/xmlenc#sha256'
```

Kimai requires an email mapping. This app sends `uid`, `displayName`, and `mail` for users that have those Nextcloud profile values.

If Kimai runs behind a reverse proxy, configure its **trusted proxies** and forward the original HTTPS protocol, host, and port. Otherwise Kimai may reject the SAML response because it sees an internal HTTP URL or port. Clear Kimai's cache after changing `local.yaml` or proxy settings.

For Kimai options, proxy guidance, and troubleshooting, see the official [Kimai SAML documentation](https://www.kimai.org/documentation/saml.html).

## Signing AuthnRequests

The default Kimai example does not sign AuthnRequests, so **Require signed AuthnRequests** must remain disabled in the Nextcloud service configuration.

For another Service Provider that supports signed AuthnRequests:

1. Export its public X.509 certificate in PEM format.
2. Open the connected service details in Nextcloud.
3. Paste the certificate into **Service Provider certificate**.
4. Enable **Require signed AuthnRequests**.
5. Configure the Service Provider to sign requests with the corresponding private key.

Unsigned requests or requests signed with a different certificate will then be rejected.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| SAML response is rejected because the ACS URL is `http` or contains an internal port | Configure the reverse proxy headers and Kimai's `TRUSTED_PROXIES`; clear Kimai's cache. |
| `Reference validation failed` | Verify that Kimai uses the current Nextcloud public certificate, copied as a single Base64 line, and that the app version includes XMLDSig compatibility fixes. |
| Login fails because an attribute is missing | Confirm that the Nextcloud user has an email address and that the Kimai mapping uses `mail` / `displayName` exactly as shown. |
| Login works but users are not matched as expected | Check the email address in Nextcloud and the Kimai SAML mapping. Kimai uses the configured email mapping when creating or matching users. |
| Certificate was regenerated | Update the IdP certificate in every Service Provider and clear each service's configuration cache if applicable. |
| The IdP does not return to the service | Check browser developer tools for CSP errors and verify that the ACS hostname is exactly the registered service URL. |

For Kimai-specific configuration and behavior, see the official [Kimai SAML documentation](https://www.kimai.org/documentation/saml.html).

## SAML security limitations

- This IdP does not keep a server-side replay cache for issued assertions. Service Providers should validate `InResponseTo`, assertion IDs, timestamps, audience, recipient, and XML signatures, and reject replayed responses.
- SP-initiated Single Logout is not implemented as a validated SAML LogoutRequest flow.

## Quality assurance and release process

This project uses a layered test approach. Unit tests validate application behavior in isolation; local test doubles are intentionally only behavioral fixtures, not the authority for Nextcloud API compatibility. The dynamically discovered real Nextcloud integration matrix validates installation, framework contracts, and API compatibility. The Kimai test validates a complete SAML SSO exchange with a real external Service Provider. A later stage is never started after a failed earlier stage.

### Coverage policy

PHPUnit calculates line coverage across the complete `lib/` directory. The mandatory CI gate is **80% line coverage** across this full production scope; no production paths are excluded merely to increase the percentage. The project aims to keep the observed result materially above that gate so normal maintenance changes have room without weakening the baseline.

Coverage is a useful safety signal, not proof of correctness. In particular, meaningful SAML security checks, input validation, XML parsing, signature verification, controller authorization, and failure paths are tested with assertions in addition to being executed.

### Fail-closed pipeline

GitHub Actions runs the following dependency chain:

```text
Unit tests
    -> Nextcloud integration tests
        -> Kimai SAML interoperability test
            -> Release app (main only)
```

Each downstream workflow is triggered through `workflow_run` only when its direct predecessor concluded successfully. It checks out the exact predecessor commit (`head_sha`) rather than an arbitrary newer branch state. Therefore:

- a failed unit-test run blocks integration tests, Kimai, and release;
- a failed Nextcloud integration run blocks Kimai and release;
- a failed Kimai run blocks release; and
- a release run first confirms that the tested commit is still the current `main` commit, avoiding a release of a superseded revision.

Unit tests run on the currently supported PHP versions discovered from the lifecycle API. Before PHPUnit starts, CI verifies the complete browser and server-side localization catalogs and checks the unit-test suite itself for a minimum number of test methods and assertion sites, empty test files, and explicit coverage mapping for the production surface. It then installs the Composer dependencies, runs PHPUnit, generates Clover coverage, enforces the 80% full-`lib/` gate, and uploads the report to Codecov. A Codecov upload issue does not turn a successful test suite into a failed build. Framework compatibility is deliberately not inferred from unit-test doubles; it is enforced by the real-container API contract stage described below.

### Nextcloud integration tests

After the unit-test workflow succeeds, the integration workflow discovers supported stable Nextcloud major releases (33 and later) and, when an explicit current Docker RC or beta Apache image exists, adds that pre-release image to the matrix. Each matrix job first runs the **Public API Preflight**: it rejects private Nextcloud implementation references such as `OC::$server`, `OC::`, `lib/private`, and the legacy CSP nonce locator in both production and test code. The failure output names the file, line, and expected replacement direction; no browser test is started after such a finding.

The job then starts the selected official Nextcloud Apache image, mounts the app read-only, installs an ephemeral SQLite-backed instance, enables the app, and runs `tests/Integration/nextcloud-api-contract.php` **inside that exact image**. The contract verifies every public `OCP` type, method, constant, base class, response class, migration type, and attribute used by production code. A missing public contract is reported as an upstream compatibility finding, before endpoint checks or Kimai start. Finally, the smoke test verifies that metadata is unavailable without IdP material (`404`) and that SSO without an AuthnRequest is rejected (`400`). Container and application logs are shown on failure.

### Kimai SAML browser end-to-end test

After every Nextcloud integration matrix job succeeds, the Kimai workflow independently tests the same stable and available RC/beta Nextcloud image matrix. For each matrix entry it creates a private Docker network with the selected Nextcloud IdP, `mariadb:11.4`, Kimai, and headless Chromium.

The job runs these layers in order:

1. **Public API Preflight** — the same private-API guard runs before containers are provisioned.
2. **Nextcloud public API contract** — runs inside the selected Nextcloud image before SAML configuration.
3. **Kimai public SAML HTTP preflight** — Kimai must expose valid SAML metadata containing its expected ACS URL, and its public SAML login endpoint must redirect to the configured Nextcloud IdP.
4. **Negative browser SSO** — wrong Nextcloud credentials must remain on the IdP login page and must not reach Kimai's ACS.
5. **Positive browser SSO** — a real browser starts at Kimai, authenticates at Nextcloud, returns via the signed SAML POST, and must reach an authenticated Kimai page after an accepted ACS redirect.
6. **Populated admin-page capture** — a fresh authenticated browser opens the public Nextcloud admin settings route and requires both IdP settings and the registered `Kimai E2E` Service Provider to be visible. It writes `docs/admin-settings-e2e-nc<target>.png` (for example, `docs/admin-settings-e2e-nc34.png`) from this populated page and adds it to the diagnostic artifact.

The E2E tests do not parse or replay Nextcloud HTML, CSRF tokens, generated form actions, SAML values, internal Nextcloud APIs, or Kimai database tables. They use normal browser controls and public HTTP endpoints, then assert user-visible outcomes. Failed runs preserve screenshots, browser traces, HTTP metadata, and container logs. Successful runs upload each populated admin screenshot as a versioned E2E artifact for the release stage.

Diagnostics are named with the app version, Nextcloud matrix target, GitHub run ID, and retry attempt, for example `kimai-saml-browser-v0.7.26-nc34-run123456789-attempt1.zip`.

### Releases and App Store publication

The **Release app** workflow runs only after the green Kimai workflow for the current `main` commit and only inside the protected `release` environment. It is fail-closed: if a required screenshot, signing credential, App Store credential, or validation step is missing, the release fails instead of publishing a partial GitHub-only release.

The workflow performs these steps:

1. confirms that the tested SHA is still the current `main` tip;
2. derives the tested stable Nextcloud compatibility range and skips scheduled releases when that range is unchanged;
3. imports the populated admin-page screenshots from the **exact successful Kimai E2E workflow run**, requiring one valid PNG per successful stable matrix job;
4. updates `appinfo/info.xml`, the changelog, README compatibility marker, and App Store screenshot URLs; then commits those release metadata changes and creates an annotated tag;
5. stages a **runtime-only** `saml_provider/` directory containing only `appinfo/`, `lib/`, `templates/`, `js/`, `css/`, `img/`, `l10n/`, and `LICENSE`;
6. signs that staging directory using `occ integrity:sign-app`, requiring the generated `appinfo/signature.json`, and validates the allowlist again immediately before archiving;
7. publishes that signed archive as the GitHub Release asset; and
8. registers that exact public GitHub Release asset with the Nextcloud App Store API.

The installed App Store archive deliberately excludes `.github/`, `tests/`, `docs/`, `scripts/`, `build/`, `.git/`, Composer and PHPUnit development metadata, and repository documentation such as this README and the changelog. These files remain in the source repository but are not installed on Nextcloud servers.

### Required protected release configuration

The protected `release` environment must contain:

| Configuration | Purpose |
| --- | --- |
| `NEXTCLOUD_SIGNING_PRIVATE_KEY` | Signs the staged app through Nextcloud's integrity-signing command and authenticates the App Store submission. |
| `NEXTCLOUD_SIGNING_CERTIFICATE` | Public certificate used while creating `appinfo/signature.json`. |
| `NEXTCLOUD_APPSTORE_TOKEN` | API token from the maintainer's Nextcloud App Store account, used to register the published archive. |

There is no separate opt-in switch for App Store publication: a successful release is intended to reach both GitHub Releases and the Nextcloud App Store. Missing protected credentials cause a failure rather than an incomplete release.

### Release-loop protection

The release workflow commits updated metadata and screenshots back to `main`. That push starts normal CI again, but the release commit contains the marker `[skip automated release]`. The release job explicitly refuses workflow chains whose triggering commit contains that marker. Therefore the bot-generated commit can be tested, but cannot generate a second release.

The App Store listing is generated from `appinfo/info.xml`, including the summary, description, license, supported Nextcloud versions, repository URL, issue tracker URL, author, and public screenshot URLs. The release workflow writes current validated E2E screenshot URLs into that metadata; an image merely existing in `docs/` is not displayed until it is referenced there.

## Security notes

- Use HTTPS everywhere.
- The app sends assertions only to the registered ACS URL. A different ACS URL in an AuthnRequest is ignored.
- Responses and assertions are signed with RSA-SHA256.
- SAML Responses and Assertions expire after five minutes.
- The IdP private key is stored as a sensitive Nextcloud app configuration value. Protect Nextcloud configuration and database backups accordingly.
- Enable signed AuthnRequests where the Service Provider supports them.
- RelayState redirects are restricted to local paths or the exact scheme/host/port origin of a configured service endpoint.
- Test with a non-administrator account before enabling SAML-only login at any connected service.
- Regenerating the IdP certificate invalidates trust at every Service Provider until its configuration is updated.

## Scope and limitations

Implemented:

- SAML 2.0 Web Browser SSO profile
- SP-initiated and IdP-initiated login
- Signed responses and assertions
- Optional signed AuthnRequest validation

Not implemented:

- Encrypted assertions
- Artifact binding
- Attribute Query
- Service Provider-initiated and propagated Single Logout requests
- Per-service assertion encryption

## License

MIT. See [LICENSE](LICENSE).

## Development and tests

Install development dependencies with Composer:

```bash
composer install
```

Run the unit test suite:

```bash
composer test
```

Generate a Clover report and enforce the line-coverage gate:

```bash
XDEBUG_MODE=coverage composer test:coverage
```

The coverage command writes `build/coverage/clover.xml` and fails when line coverage is below **80%**.

GitHub Actions discovers currently supported PHP versions from the [endoflife.date PHP API](https://endoflife.date/php), tests each one, and runs weekly as well as on push, pull request, and manual dispatch. Before PHPUnit, it executes the Public API Preflight described above.

The downstream Nextcloud workflow discovers supported **Nextcloud 33 or later** majors from the [Nextcloud lifecycle API](https://endoflife.date/nextcloud), plus the newest explicitly versioned Apache RC/beta image when available. It deliberately never uses Docker Hub's generic `beta` tag. The downstream Kimai workflow repeats that version matrix as a real browser SAML interoperability test.

The unit-test bootstrap loads lightweight behavioral fixtures for public `OCP` interfaces only. Those fixtures are not used as a compatibility authority: the public API contract is executed inside every selected real Nextcloud image.
