# Contributing

Thank you for contributing.

## Before opening a pull request

1. Open an issue first for substantial changes.
2. Keep changes focused and include tests for behavior changes.
3. Run the local test and coverage gate:

   ```bash
   composer install
   XDEBUG_MODE=coverage composer test:coverage
   ```

4. Ensure the GitHub workflows are green.
5. Update `README.md` and `CHANGELOG.md` when user-visible behavior changes.

## Pull request guidelines

- Explain the problem and the solution.
- Do not include credentials, private keys, certificates, production URLs, or real user data.
- Preserve backwards compatibility where feasible.
- Add migration-free schema changes carefully; this app uses `appinfo/database.xml` for fresh installs.

## Development scope

The app supports Nextcloud 33 and later. The repository automatically tests supported Nextcloud releases and explicit current pre-release images when available.
