#!/usr/bin/env bash
# Isolated full SAML interoperability test. It deliberately has no release/App Store secrets.
set -euo pipefail
network="saml-e2e-${E2E_TARGET_SLUG:-nextcloud}"; nextcloud=e2e-nextcloud; kimai=e2e-kimai; mariadb=e2e-mariadb
workspace="${GITHUB_WORKSPACE:-$PWD}"
completed=false
cleanup(){
  if [[ "$completed" == true ]]; then
    docker rm --force "$nextcloud" "$kimai" "$mariadb" 2>/dev/null || true
    docker network rm "$network" 2>/dev/null || true
  fi
}
trap cleanup EXIT
fail(){ echo "E2E failure: $*" >&2; exit 1; }
wait_http(){
  local url="$1" name="$2" status
  for attempt in $(seq 1 90); do
    status="$(docker run --rm --network "$network" curlimages/curl:8.10.1 --silent --output /dev/null --write-out '%{http_code}' "$url" || true)"
    # Kimai correctly redirects anonymous requests (302) to its login page. Readiness
    # means the application answered; endpoint-specific assertions below remain strict.
    if [[ "$status" =~ ^[123][0-9]{2}$ ]]; then return 0; fi
    sleep 2
  done
  docker logs "$name" >&2 || true
  fail "Timed out waiting for $url (last HTTP status: ${status:-none})"
}
docker network create "$network" >/dev/null
docker run -d --name "$nextcloud" --network "$network" -v "$workspace:/var/www/html/custom_apps/saml_provider:ro" "${NEXTCLOUD_IMAGE:-nextcloud:34-apache}" >/dev/null
wait_http http://e2e-nextcloud/status.php "$nextcloud"
docker exec --user www-data "$nextcloud" php occ maintenance:install --database sqlite --database-name nextcloud --admin-user admin --admin-pass integration-test-password --data-dir /var/www/html/data >/dev/null
# Disable Nextcloud's first-run wizard in this ephemeral test instance before any browser login.
# This keeps the E2E flow and documentation capture focused on the populated SAML settings.
docker exec --user www-data "$nextcloud" php occ app:disable firstrunwizard >/dev/null
# The requests below originate from the Docker network hostname. Add it only to this
# ephemeral test installation; otherwise Nextcloud correctly rejects it with HTTP 400.
docker exec --user www-data "$nextcloud" php occ config:system:set overwrite.cli.url --value=http://e2e-nextcloud >/dev/null
docker exec --user www-data "$nextcloud" php occ config:system:set trusted_domains 1 --value=e2e-nextcloud >/dev/null
docker exec --user www-data "$nextcloud" php occ app:enable saml_provider >/dev/null
echo '================================================================='
echo "NEXTCLOUD PUBLIC API PREFLIGHT: target ${NEXTCLOUD_IMAGE:-nextcloud:34-apache}"
echo 'This checks only documented OCP interfaces used by the application.'
echo '================================================================='
docker exec --user www-data "$nextcloud" env NEXTCLOUD_VERSION="${E2E_TARGET_SLUG:-unknown}" php /var/www/html/custom_apps/saml_provider/tests/Integration/nextcloud-api-contract.php
# Kimai is registered later through the authenticated Nextcloud admin UI.
# This ensures the real CSRF, SettingsController, validation, mapper and DBAL paths run.
mkdir -p build/e2e/browser-artifacts
printf 'Kimai E2E diagnostics initialized for %s\n' "${NEXTCLOUD_IMAGE:-nextcloud:34-apache}" > build/e2e/browser-artifacts/e2e-context.txt
# The real browser-admin configuration runs after Playwright is prepared below.
# Drive the entire user journey in a real browser. No Nextcloud login HTML,
# CSRF representation, form action, or SAML POST form is parsed or replayed.
# Pull before the trace marker: the marker now denotes the actual browser test.
playwright_image="${PLAYWRIGHT_IMAGE:-mcr.microsoft.com/playwright:v1.62.1-noble}"
docker pull "$playwright_image"
mkdir -p "$workspace/build/e2e/browser-artifacts"
# The browser image supplies browsers and OS dependencies, but no importable
# project module. Prepare the matching Node package in a temporary work directory.
playwright_work="$workspace/build/e2e/playwright-work"
rm -rf "$playwright_work"
mkdir -p "$playwright_work"
# Bootstrap npm 12 as a local dependency, never as a global self-upgrade.
# npm self-updates in global mode ignore the writable prefix in this image and try
# to rename /usr/lib/node_modules/npm. The local binary stays under /work.
playwright_setup='node /work/bootstrap-npm.mjs && npm_cmd="node /work/npm-tool/bin/npm-cli.js" && test "$($npm_cmd --version)" = 12.0.2 && cd /work && $npm_cmd install --no-save --ignore-scripts --no-update-notifier playwright@1.62.1 && node -e "import(\"playwright\").then(({chromium}) => { if (!chromium) process.exit(1) })"'
docker run --rm --user "$(id -u):$(id -g)" \
  --volume "$playwright_work:/work" \
  --volume "$workspace/tests/E2E/bootstrap-npm.mjs:/work/bootstrap-npm.mjs:ro" \
  --env PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
  --env npm_config_cache=/tmp/npm-cache \
  "$playwright_image" \
  sh -ec "$playwright_setup"
# Configure the IdP through the production Nextcloud admin interface.
# No SQL fixture or direct app-config write is used for the application under test.
docker run --rm --network "$network" --ipc=host --user "$(id -u):$(id -g)" \
  --volume "$playwright_work:/work" \
  --volume "$workspace/tests/E2E/configure-kimai-admin.mjs:/work/configure-kimai-admin.mjs:ro" \
  --volume "$workspace/build/e2e/browser-artifacts:/work/browser-artifacts" \
  --env E2E_ARTIFACT_DIR=/work/browser-artifacts \
  --env E2E_KIMAI_CONFIG=/work/browser-artifacts/kimai-idp.json \
  "$playwright_image" node /work/configure-kimai-admin.mjs
kimai_idp_json="$workspace/build/e2e/browser-artifacts/kimai-idp.json"
[[ -s "$kimai_idp_json" ]] || fail 'Admin browser setup did not produce Kimai IdP configuration'
certificate="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["certificate"].replace("\n", ""))' "$kimai_idp_json")"
[[ -n "$certificate" ]] || fail 'Admin browser setup produced no IdP certificate'
cat > build/e2e/kimai-local.yaml <<YAML
kimai:
  saml:
    provider: nextcloud
    activate: true
    connection:
      baseurl: 'http://e2e-nextcloud'
      entity_id: 'http://e2e-nextcloud/apps/saml_provider/saml/metadata'
      sso_url: 'http://e2e-nextcloud/apps/saml_provider/saml/sso'
      x509cert: '$certificate'
    user:
      username: Email
      roles: []
YAML
docker run -d --name "$mariadb" --network "$network" -e MARIADB_DATABASE=kimai -e MARIADB_USER=kimai -e MARIADB_PASSWORD=kimai -e MARIADB_ROOT_PASSWORD=root-password mariadb:11.4 >/dev/null
for attempt in $(seq 1 60); do docker exec "$mariadb" mariadb-admin ping -h localhost -uroot -proot-password --silent && break; [[ "$attempt" == 60 ]] && fail 'MariaDB did not become ready'; sleep 2; done
docker run -d --name "$kimai" --network "$network" -e 'DATABASE_URL=mysql://kimai:kimai@e2e-mariadb:3306/kimai?charset=utf8mb4&serverVersion=11.4.0-MariaDB' -e APP_SECRET=kimai-e2e-only -e TRUSTED_HOSTS='e2e-kimai|localhost|127\\.0\\.0\\.1' -e TRUSTED_PROXIES='127.0.0.1,172.16.0.0/12' -v "$workspace/build/e2e/kimai-local.yaml:/opt/kimai/config/packages/local.yaml:ro" "${KIMAI_IMAGE:-kimai/kimai2:apache}" >/dev/null
wait_http http://e2e-kimai:8001/ "$kimai"
kimai_metadata_response="$(docker run --rm --network "$network" curlimages/curl:8.10.1 --silent --show-error --write-out $'\n%{http_code}' http://e2e-kimai:8001/auth/saml/metadata)" || fail 'Could not contact Kimai SAML metadata endpoint'
kimai_metadata_status="${kimai_metadata_response##*$'\n'}"
metadata="${kimai_metadata_response%$'\n'*}"
printf '%s' "$metadata" > build/e2e/browser-artifacts/kimai-saml-metadata-response.txt
[[ "$kimai_metadata_status" =~ ^2[0-9][0-9]$ ]] || fail 'Kimai SAML metadata endpoint did not return success'
printf '%s' "$metadata" | grep -Fq 'http://e2e-kimai:8001/auth/saml/acs' || fail 'Kimai metadata does not advertise its expected ACS URL'
kimai_login_headers="$(docker run --rm --network "$network" curlimages/curl:8.10.1 --silent --show-error --head http://e2e-kimai:8001/auth/saml/login)" || fail 'Could not contact Kimai SAML login endpoint'
printf '%s\n' "$kimai_login_headers" > build/e2e/browser-artifacts/kimai-saml-login-headers.txt
printf '%s\n' "$kimai_login_headers" | grep -Eiq '^location: http://e2e-nextcloud/' || fail 'Kimai SAML login endpoint has no expected Nextcloud redirect'
echo 'KIMAI SAML HTTP PREFLIGHT: PASSED. Metadata is valid and login redirects to the admin-configured Nextcloud IdP.'
echo '================================================================='
echo 'KIMAI SAML BROWSER E2E STARTS - copy logs from this line'
echo 'Playwright SDK and browser image: 1.62.1'
echo '================================================================='
# Run isolated negative and positive browser sessions. Both use the same real
# Kimai -> Nextcloud route; only the negative session uses wrong IdP credentials.
run_browser() {
  local mode="$1"
  docker run --rm --network "$network" --ipc=host --user "$(id -u):$(id -g)" \
    --volume "$playwright_work:/work" \
    --volume "$workspace/tests/E2E/kimai-saml-browser.mjs:/work/kimai-saml-browser.mjs:ro" \
    --volume "$workspace/build/e2e/browser-artifacts:/work/browser-artifacts" \
    --env E2E_ARTIFACT_DIR=/work/browser-artifacts \
    --env E2E_SSO_MODE="$mode" \
    "$playwright_image" \
    node /work/kimai-saml-browser.mjs
}

echo 'Running negative IdP authentication test'
run_browser negative
# The negative browser test already verifies the observable security outcome:
# invalid IdP credentials remain at Nextcloud and never reach Kimai's public ACS.
# Do not inspect Kimai's private database schema merely to restate that result.
echo 'Invalid Nextcloud credentials correctly produced no Kimai ACS request.'

echo 'Running positive IdP authentication test'
run_browser positive
# A successful post-ACS browser navigation is the public, user-visible Kimai contract.
# Do not depend on Kimai's internal user-table names or persistence layout.
echo 'Kimai accepted the signed SAML response and established a browser session.'

echo 'Capturing populated Nextcloud SAML Provider admin settings for documentation'
mkdir -p "$workspace/docs"
screenshot_target="${E2E_TARGET_SLUG:-nextcloud}"
screenshot_target="${screenshot_target//[^A-Za-z0-9._-]/-}"
documentation_screenshot="/work/docs/admin-settings-e2e-nc${screenshot_target}.png"
docker run --rm --network "$network" --ipc=host --user "$(id -u):$(id -g)" \
  --volume "$playwright_work:/work" \
  --volume "$workspace/tests/E2E/nextcloud-admin-screenshot.mjs:/work/nextcloud-admin-screenshot.mjs:ro" \
  --volume "$workspace/build/e2e/browser-artifacts:/work/browser-artifacts" \
  --volume "$workspace/docs:/work/docs" \
  --env E2E_ARTIFACT_DIR=/work/browser-artifacts \
  --env E2E_DOCUMENTATION_SCREENSHOT="$documentation_screenshot" \
  "$playwright_image" \
  node /work/nextcloud-admin-screenshot.mjs
[[ -s "$workspace/docs/admin-settings-e2e-nc${screenshot_target}.png" ]] || fail "Admin settings screenshot was not written to docs/admin-settings-e2e-nc${screenshot_target}.png"
echo "Captured docs/admin-settings-e2e-nc${screenshot_target}.png from the populated E2E admin interface."
# Keep the locally installed Playwright module until every browser-based check,
# including the documentation capture, has completed.
rm -rf "$playwright_work"
echo 'Kimai SAML browser end-to-end test passed.'
completed=true
echo '=========================================================='
echo 'KIMAI SAML BROWSER E2E ENDED SUCCESSFULLY - end of trace'
echo '=========================================================='
