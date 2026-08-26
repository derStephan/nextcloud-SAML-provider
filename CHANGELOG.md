# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project uses [Semantic Versioning](https://semver.org/).

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
