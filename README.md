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
- Per-service configuration: Entity ID, ACS URL, optional , NameID format, attributes, and SP certificate
- Optional validation of signed AuthnRequests for each service
- Nextcloud administration interface and user-facing launcher

## Security and operational limits

- HTTP-Redirect AuthnRequests are limited to 1 MiB after Base64 decoding and DEFLATE expansion.
- AuthnRequest IssueInstant values must be within five minutes of the IdP clock; synchronize IdP and Service Provider clocks with NTP or Chrony.
- Redirect-binding signatures are verified over the raw query string obtained through Nextcloud’s request abstraction; reverse proxies must preserve the original query string.

## Development transparency

This project was developed with assistance from **GPT 5.6 Terra by OpenAI**, including support for implementation, test coverage, CI/CD configuration, documentation, and release-process improvements. Human maintainers remain responsible for technical review, security assessment, testing, deployment decisions, and every published release.

## Translations

The app includes English and German plus structurally synchronized draft catalogues for 18 additional widely spoken languages: Arabic, Bengali, Chinese (Simplified), Spanish, French, Hindi, Indonesian, Italian, Japanese, Korean, Polish, Portuguese (Brazil), Russian, Thai, Turkish, Ukrainian, Urdu, and Vietnamese. Please review translations in your native language before relying on them in production, especially security-related wording. English remains the fallback for strings awaiting community review.

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
- Single Logout is not exposed. It will be added only with a validated SAML LogoutRequest/LogoutResponse flow.

## Quality assurance and release process

This project uses a layered test approach. Unit tests validate application behavior in isolation; explicitly named local mapper doubles are only controller fixtures. Production `ServiceProviderMapper` persistence is validated separately through the real DBAL-backed integration contract, not through those doubles. The reviewed, repository-controlled Nextcloud integration matrix validates installation, framework contracts, database portability, and API compatibility. The Kimai test validates a complete SAML SSO exchange with a real external Service Provider. A later stage is never started after a failed earlier stage.

### Coverage policy

PHPUnit calculates line coverage across the complete `lib/` directory. The mandatory CI gate is **80% line coverage** across this full production scope; no production paths are excluded merely to increase the percentage. The project aims to keep the observed result materially above that gate so normal maintenance changes have room without weakening the baseline.

Coverage is a useful safety signal, not proof of correctness. In particular, meaningful SAML security checks, input validation, XML parsing, signature verification, controller authorization, and failure paths are tested with assertions in addition to being executed.

### Controlled CI and release process

GitHub Actions uses this validation chain:

```text
Unit tests
    -> Nextcloud integration tests
        -> Kimai SAML interoperability test
```

Each downstream workflow starts only when its direct predecessor succeeds and checks out that predecessor’s exact commit SHA. The reviewed CI matrix is deliberately fixed in the repository: PHP 8.1–8.3 and Nextcloud 33/34, with SQLite, MariaDB, and PostgreSQL for integration contracts. Changing the supported matrix requires a reviewed pull request; an external lifecycle or Docker Hub API cannot silently change release evidence.

The **Release app** workflow is intentionally separate from CI. It has no `push` or `workflow_run` trigger: a maintainer starts it manually from `main`, supplies the exact semantic version, proven Nextcloud range, and specific user-visible release notes, and passes the protected `release` environment approval gate. The release workflow then updates only the selected metadata, creates an annotated tag, signs a runtime-only App Store archive, verifies that archive, and creates the GitHub release.

The runtime archive is built from an explicit allowlist (`appinfo`, `css`, `img`, `js`, `l10n`, `lib`, `templates`, and `LICENSE`). Source-only material—CI definitions, tests, documentation, build output, development dependencies, and signing files—is rejected before and after signing. `appinfo/signature.json` is expected only in the disposable post-signing staging directory, never in the source repository.

### Persistent NameID privacy

Persistent NameIDs are derived with HMAC-SHA256 from the Nextcloud user ID, the service-provider entity ID, and an installation-specific random secret stored as sensitive app configuration. They are stable for a given user and service provider but cannot be reconstructed from a known UID namespace without that secret.

### Dependency and image provenance

CI uses versioned action, container, npm, Composer, and Playwright references. The direct PHPUnit development dependency is fixed to version `10.5.0`; a reviewed `composer.lock` must still be generated in a Composer-capable environment before dependency resolution is fully reproducible. The browser E2E bootstrap still downloads the npm CLI and Playwright dependency graph during the run. It verifies downloaded bytes against registry-provided integrity metadata, which is useful transport integrity but is not an independently pinned supply-chain attestation. Likewise, Docker image tags are versioned but not digest-pinned. These are explicit residual supply-chain limitations, not release guarantees. Production archives contain no Composer or npm dependencies.


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
