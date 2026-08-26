# SAML Provider for Nextcloud

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

## Requirements

- Nextcloud 29 through 34
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

## Complete example: Kimai

This example connects a Kimai installation at `https://kimai.example.com` to a Nextcloud IdP at `https://cloud.example.com`.

### 1. Create the service in Nextcloud

In **Administration settings → SAML Provider → Connect a new service**, create an entry with:

| Nextcloud field | Value |
| --- | --- |
| Name | `Kimai` |
| Entity ID | `https://kimai.example.com/auth/saml/metadata` |
| ACS URL | `https://kimai.example.com/auth/saml/acs` |

In the service details, use the default **Email address** NameID format. Leave **Require signed AuthnRequests** disabled unless Kimai has been configured with its own signing certificate and signed AuthnRequests are required.

### 2. Add Kimai's SAML configuration

Create or update Kimai's `config/packages/local.yaml` with the following configuration. Replace the certificate placeholder with the **single-line** certificate displayed by the Nextcloud SAML Provider app. Do not include the PEM `BEGIN CERTIFICATE` / `END CERTIFICATE` lines or any line breaks.

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
                x509cert: 'PASTE_THE_NEXTCLOUD_CERTIFICATE_AS_ONE_BASE64_LINE_HERE'

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
                nameIdEncrypted: false
                authnRequestsSigned: false
                logoutRequestSigned: false
                logoutResponseSigned: false
                wantMessagesSigned: true
                wantAssertionsSigned: true
                wantNameIdEncrypted: false
                requestedAuthnContext: true
                signMetadata: false
                wantXMLValidation: true
                signatureAlgorithm: 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256'
                digestAlgorithm: 'http://www.w3.org/2001/04/xmlenc#sha256'
```

Kimai requires an email mapping for SAML users. The `mail` and `displayName` attributes used above are sent by this app.

### 3. Kimai behind a reverse proxy

If Kimai runs in Docker behind a reverse proxy, Kimai must trust that proxy. Otherwise it can interpret the ACS URL as an internal HTTP URL (for example, `http://…:8001`) and reject the response.

Set `TRUSTED_PROXIES` to the proxy's IP address as seen by the Kimai container. Use one IP address per value if the installed Symfony version has issues with CIDR notation. Confirm the effective value inside the container:

```bash
docker compose exec kimai sh -lc 'printf "%s\n" "$TRUSTED_PROXIES"'
```

The reverse proxy must forward the original protocol, host, and port. A Caddy example is:

```caddy
kimai.example.com {
    reverse_proxy kimai:8001 {
        header_up Host {host}
        header_up X-Forwarded-Host {host}
        header_up X-Forwarded-Proto https
        header_up X-Forwarded-Port 443
        header_up X-Forwarded-For {remote_host}
    }
}
```

### 4. Reload Kimai and test

After changing `local.yaml` or proxy environment variables, recreate the Kimai container if needed and clear its cache:

```bash
docker compose up -d --force-recreate kimai
docker compose exec kimai /opt/kimai/bin/console cache:clear --env=prod
```

Open Kimai's login screen and choose **Login with Nextcloud**. After authenticating with Nextcloud, Kimai should create or match the user using the SAML email attribute.

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

## Security notes

- Use HTTPS everywhere.
- The app sends assertions only to the registered ACS URL. A different ACS URL in an AuthnRequest is ignored.
- Responses and assertions are signed with RSA-SHA256.
- SAML Responses and Assertions expire after five minutes.
- The IdP private key is stored as a sensitive Nextcloud app configuration value. Protect Nextcloud configuration and database backups accordingly.
- Enable signed AuthnRequests where the Service Provider supports them.
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
