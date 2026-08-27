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
        -> Kimai SAML end-to-end test
            -> Release app (main only)
```

Each downstream workflow is triggered through `workflow_run` only when its direct predecessor concluded successfully. It checks out the exact predecessor commit (`head_sha`) rather than an arbitrary newer branch state. Therefore:

- a failed unit-test run blocks integration tests, Kimai, and release;
- a failed Nextcloud integration run blocks Kimai and release;
- a failed Kimai run blocks release; and
- a release run first confirms that the tested commit is still the current `main` commit, avoiding a release of a superseded revision.

Unit tests run on the currently supported PHP versions discovered from the lifecycle API. They install the Composer dependencies, run PHPUnit, generate Clover coverage, enforce the 80% full-`lib/` gate, and upload the report to Codecov. A Codecov upload issue does not turn a successful test suite into a failed build. Framework compatibility is deliberately not inferred from unit-test doubles; it is enforced by the real-container API contract stage described below.

### Nextcloud integration tests

After the unit-test workflow succeeds, the integration workflow discovers supported stable Nextcloud major releases (33 and later) and, when an explicit current Docker RC or beta Apache image exists, adds that pre-release image to the matrix. Each matrix job:

1. starts the selected official Nextcloud Apache Docker image;
2. mounts this app read-only as `custom_apps/saml_provider`;
3. installs an ephemeral SQLite-backed Nextcloud instance;
4. enables the app and verifies that Nextcloud reports it as enabled;
5. verifies the expected initial endpoint behavior: metadata is unavailable until IdP material exists (`404`) and SSO without an AuthnRequest is rejected (`400`).

Container and application logs are printed if a smoke test fails, and the container is removed in all cases.

Before endpoint checks, each matrix container also runs `tests/Integration/nextcloud-api-contract.php` **inside the selected Nextcloud image**. It verifies the exact public OCP interfaces, methods, constants, base classes, response classes, migration types, and attributes used by production code. Because the version matrix is discovered dynamically, this check covers every current supported stable release and any available RC/beta image — not merely Nextcloud 33 or the local unit-test doubles. A removed or renamed framework API therefore blocks the integration stage before Kimai or release.

### Kimai SAML end-to-end test

After all Nextcloud matrix jobs are green, the `Kimai SAML end-to-end test` launches a private Docker network containing:

- `nextcloud:34-apache` as the IdP under test;
- `mariadb:11.4` for Kimai; and
- `kimai/kimai2:apache` as a real SAML Service Provider.

The test installs and enables the app in an ephemeral Nextcloud instance, checks that the `oc_saml_provider_sp` migration table exists, creates test-only self-signed IdP key material inside the container, and verifies that the IdP metadata endpoint responds. It then creates Kimai's SAML configuration from the test IdP endpoint and certificate, waits for MariaDB and Kimai to become ready, and verifies all of the following:

- Kimai publishes SAML metadata containing an `EntityDescriptor`;
- that metadata advertises the expected Kimai ACS URL; and
- Kimai's SAML ACS endpoint is enabled (it must not return `404`);
- a full SP-initiated SSO exchange with a shared cookie jar: Kimai creates the AuthnRequest, Nextcloud redirects the anonymous client to its login page, the temporary administrator authenticates, Nextcloud returns a signed POST-binding response, and the test submits it to Kimai's ACS; and
- provisioning: Kimai's real MariaDB database must contain exactly one imported user with the configured email and `saml` authentication mode.

This is a full protocol-level HTTP test: it validates redirects, cookies, the real Nextcloud login form, AuthnRequest parsing, SP lookup, signed SAML POST binding, Kimai ACS validation, and SAML-user import. The generated SAML POST form is parsed by a dedicated HTML parser rather than by assumptions about HTML attribute order. It deliberately does not assert visual rendering or JavaScript behavior, which are separate UI concerns.

After a successful run, all containers, the temporary network, test certificates, database, browser-session files, and configuration are discarded. On failure, the test deliberately leaves its temporary containers alive long enough for the GitHub Actions diagnostic step to collect container logs; that workflow step then removes them. The temporary HTTP client runs with the CI runner's numeric UID/GID solely to write its cookie jar and response captures into the bind-mounted ephemeral test directory. The workflow receives no release credentials, signing keys, or App Store token.

### Releases and App Store publication

A release workflow can run only after the green Kimai workflow and only for a successful `main` commit. It runs in the protected `release` environment and performs these steps:

1. confirms that the tested SHA remains the current `main` tip;
2. derives the tested stable Nextcloud compatibility range;
3. decides whether a release is required (a scheduled compatibility check skips a release when the range is unchanged);
4. updates release metadata, creates a release commit and annotated tag;
5. signs the archive with the protected Nextcloud signing key, verifies `appinfo/signature.json`, and creates `saml_provider.tar.gz`;
6. creates a GitHub Release and attaches that signed archive.

The Nextcloud App Store is deliberately **opt-in**. By default, the workflow creates the signed GitHub Release but does **not** publish to the App Store. Publication happens only when the repository variable `PUBLISH_TO_APPSTORE` is set exactly to `true`; only then is the protected `NEXTCLOUD_APPSTORE_TOKEN` used to submit the already published GitHub Release archive. This makes the default behavior a practical release sign-off/dry run for App Store delivery.

The App Store listing itself is generated from `appinfo/info.xml`, including summary, description, license, supported Nextcloud versions, repository URL, issue tracker URL, and author. Manual recovery and first-release procedures are documented in [docs/APP_STORE_RELEASE.md](docs/APP_STORE_RELEASE.md).

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

## App Store and releases

The App Store listing is generated from the metadata in `appinfo/info.xml`, including the summary, long description, license, supported Nextcloud versions, repository URL, issue tracker URL, and author.

The **Release app** workflow automates patch releases after successful **Unit tests** and **Nextcloud integration tests** on `main`. It updates the patch version, derives the Nextcloud `min-version` and `max-version` from the green stable integration matrix, signs the final archive, creates a GitHub Release, and publishes the archive to the Nextcloud App Store. A scheduled matrix check publishes only when the supported stable Nextcloud range changes. Release signing material is available exclusively to the protected `release` environment.

Manual recovery and first-release steps are documented in [docs/APP_STORE_RELEASE.md](docs/APP_STORE_RELEASE.md).

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

The GitHub Actions workflow discovers the PHP versions that are currently supported upstream at the beginning of every run, then tests each of them. It includes releases that still receive security fixes and updates automatically when PHP support status changes. The workflow also runs weekly to detect a newly supported release without requiring a code change. Lifecycle data is obtained from the [endoflife.date PHP API](https://endoflife.date/php), which tracks the upstream PHP support schedule.

A separate integration workflow discovers currently supported **Nextcloud 33 or later** major releases from the [Nextcloud lifecycle API](https://endoflife.date/nextcloud). For every supported major version, it starts the matching official Nextcloud Docker image, installs a fresh SQLite-backed instance, enables this app, and verifies that the metadata and SSO routes are registered. It also discovers the newest explicitly versioned Nextcloud RC or beta Apache image from Docker Hub and tests it when one is available. The workflow deliberately does not use Docker Hub's generic `beta` tag because that tag may point to an unrelated historical image. It runs on every push, pull request, weekly schedule, and manual dispatch.

The test bootstrap intentionally loads `tests/Support/TestDoubles.php`, which contains lightweight test-only implementations of the Nextcloud interfaces needed for isolated unit tests. This allows the suite to run without a complete Nextcloud server installation.
