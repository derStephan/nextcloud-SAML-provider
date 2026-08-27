# Release guide: SAML Provider for Nextcloud

This guide applies only to the `saml_provider` app in this repository. Its App Store metadata is maintained in `appinfo/info.xml`; its release pipeline is defined in `.github/workflows/release.yml`.

## What the automated pipeline has already proved

A release is considered only after the fail-closed CI chain has passed for the exact commit:

```text
Unit tests -> Nextcloud integration matrix -> Kimai SAML browser E2E -> Release app
```

The Nextcloud matrix covers supported Nextcloud majors from 33 onward and an explicitly versioned RC/beta image when one is available. Before functional checks, it rejects private Nextcloud APIs and validates the public `OCP` contract inside every selected image. The Kimai E2E stage validates public SAML metadata and the real negative and positive browser SSO paths.

Do not bypass a failed stage by manually creating an App Store archive. Fix the compatibility or functional finding first and run the pipeline again.

## One-time App Store preparation

1. Create the app entry at [apps.nextcloud.com](https://apps.nextcloud.com/) with the permanent app ID `saml_provider`.
2. Request an App Store code-signing certificate for the maintainer account.
3. Store the signing certificate and private key only in the protected release environment. Never commit them, add them to CI logs, or include them in an artifact.
4. Configure the repository description, issue tracker, topics, and private vulnerability reporting.
5. Review the listing metadata in `appinfo/info.xml`: title, summary, English long description, MIT license, repository URL, issue tracker, author, category, and supported Nextcloud range.

## Automated release procedure

The **Release app** workflow runs only for a successful `main` commit that still matches the tested commit. It:

1. determines the tested supported Nextcloud range;
2. updates release metadata when a release is needed;
3. creates the release commit and annotated tag;
4. signs the final app directory using the protected App Store key;
5. verifies the generated `appinfo/signature.json`;
6. creates a signed `saml_provider.tar.gz` archive and attaches it to a GitHub Release.

App Store publication is opt-in: it occurs only when the repository variable `PUBLISH_TO_APPSTORE` is exactly `true`. Without that value, the signed GitHub Release is created but nothing is submitted to the App Store.

## Manual recovery only

Use this route only when the automated release workflow cannot be used. Begin from the exact green commit and run the complete CI chain first.

1. Confirm `appinfo/info.xml` has the intended version and tested Nextcloud compatibility range.
2. Confirm `CHANGELOG.md` documents the release.
3. Build a clean archive containing exactly one top-level `saml_provider/` directory.
4. On a supported Nextcloud installation, sign the final app directory:

   ```bash
   sudo -u www-data php occ integrity:sign-app \
     --privateKey=/secure/path/saml_provider.key \
     --certificate=/secure/path/saml_provider.crt \
     --path=/path/to/saml_provider
   ```

5. Verify that `appinfo/signature.json` was created.
6. Do not change any application or documentation file after signing. If anything changes, sign again.
7. Archive the signed directory, attach that exact archive to the GitHub Release, and submit the same file to the App Store only after the normal release review.

## Screenshot evidence

Successful Kimai browser E2E jobs generate populated admin-page screenshots named `docs/admin-settings-e2e-nc<target>.png`, for example `docs/admin-settings-e2e-nc34.png`. They show the current Nextcloud design, IdP settings, and the configured `Kimai E2E` Service Provider.

The protected release workflow downloads only the screenshot artifacts belonging to the exact successful E2E workflow run that triggered the release. It verifies that every successful matrix job produced one target-named, decodable PNG, copies them into `docs/`, and commits them with the release metadata before signing and tagging. Thus a release never intentionally carries an older screenshot. If screenshot evidence is missing or incomplete, the release fails rather than publishing stale documentation.
