# Nextcloud App Store release checklist

## One-time preparation

1. Create the application entry at [apps.nextcloud.com](https://apps.nextcloud.com/).
2. Confirm that the app ID is permanently `saml_provider`.
3. Request a Nextcloud App Store code-signing certificate for the maintainer account.
4. Store the certificate and private key securely. Never commit either one to GitHub.
5. Configure GitHub repository topics, description, issue tracker, and private vulnerability reporting.

The App Store title, summary, long description, repository URL, issue tracker URL, license, compatibility range, contributor (`derStephan`), and primary category (`integration`) are read from `appinfo/info.xml` in the source release. Security is described in the listing text and feature set. The long App Store description is the `<description lang="en">` element in that file.

## Per-release steps

Normal releases are automated by the **Release app** GitHub workflow. The steps below are useful for the initial release or manual recovery only.

1. Update `appinfo/info.xml` with the release version and compatibility range.
2. Update `CHANGELOG.md`.
3. Run unit tests and the coverage gate:

   ```bash
   composer install
   XDEBUG_MODE=coverage composer test:coverage
   ```

4. Confirm GitHub Actions is green, including Nextcloud integration tests.
5. Build a clean archive containing exactly one top-level `saml_provider/` directory.
6. In a matching supported Nextcloud test installation, sign the final app directory with the App Store certificate:

   ```bash
   sudo -u www-data php occ integrity:sign-app \
     --privateKey=/secure/path/saml_provider.key \
     --certificate=/secure/path/saml_provider.crt \
     --path=/path/to/saml_provider
   ```

   This creates `appinfo/signature.json`.

7. Do not modify any app file after signing. Archive the signed `saml_provider/` directory.
8. Upload the signed archive to a GitHub Release and submit that exact archive in the Nextcloud App Store.
9. Install the archive on a clean supported Nextcloud instance and verify it before publishing.

## Important

`appinfo/signature.json` is release-specific. It is generated after the final build and should not be manually edited. Any code or documentation change after signing requires signing again.
