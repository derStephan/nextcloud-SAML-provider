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
# The requests below originate from the Docker network hostname. Add it only to this
# ephemeral test installation; otherwise Nextcloud correctly rejects it with HTTP 400.
docker exec --user www-data "$nextcloud" php occ config:system:set overwrite.cli.url --value=http://e2e-nextcloud >/dev/null
docker exec --user www-data "$nextcloud" php occ config:system:set trusted_domains 1 --value=e2e-nextcloud >/dev/null
docker exec --user www-data "$nextcloud" php occ app:enable saml_provider >/dev/null
# Kimai requires an Email assertion attribute; all values are ephemeral test data.
docker exec --user www-data "$nextcloud" php occ user:setting admin settings email admin@example.test >/dev/null
docker exec --user www-data "$nextcloud" php -r '
$db = new PDO("sqlite:/var/www/html/data/nextcloud.db");
$name = $db->query("SELECT name FROM sqlite_master WHERE type=\"table\" AND name=\"oc_saml_provider_sp\"")->fetchColumn();
if ($name !== "oc_saml_provider_sp") { fwrite(STDERR, "missing migration table\n"); exit(1); }
$stmt = $db->prepare("INSERT INTO oc_saml_provider_sp (sp_entity_id, sp_name, acs_url, slo_url, sp_certificate, name_id_format, attribute_mapping, sign_assertions, require_signed_requests, is_enabled) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute(["http://e2e-kimai:8001/auth/saml/metadata", "Kimai E2E", "http://e2e-kimai:8001/auth/saml/acs", "", "", "urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress", "{\"Email\":\"mail\",\"FirstName\":\"displayName\"}", 1, 0, 1]);
' || fail 'saml_provider_sp migration table is unavailable or cannot register Kimai'
# Test-only self-signed IdP material. It exists only inside this ephemeral Docker container.
docker exec --user www-data "$nextcloud" sh -ec 'openssl req -x509 -newkey rsa:2048 -nodes -keyout /tmp/idp.key -out /tmp/idp.crt -subj "/CN=e2e-nextcloud" -days 1 >/dev/null 2>&1; php occ config:app:set saml_provider idp_certificate --value="$(cat /tmp/idp.crt)"; php occ config:app:set saml_provider idp_private_key --value="$(cat /tmp/idp.key)"'
idp_cert="$(docker exec --user www-data "$nextcloud" php occ config:app:get saml_provider idp_certificate | awk 'BEGIN{ORS=""} !/BEGIN CERTIFICATE|END CERTIFICATE/{gsub(/[[:space:]]/,"");print}')"
[[ -n "$idp_cert" ]] || fail 'IdP certificate was not stored'
wait_http http://e2e-nextcloud/apps/saml_provider/saml/metadata "$nextcloud"
mkdir -p build/e2e/browser-artifacts
printf 'Kimai E2E diagnostics initialized for %s\n' "${NEXTCLOUD_IMAGE:-nextcloud:34-apache}" > build/e2e/browser-artifacts/e2e-context.txt
cat > build/e2e/kimai-local.yaml <<YAML
kimai:
  saml:
    provider: nextcloud
    activate: true
    title: Login with Nextcloud
    mapping:
      - { saml: \$Email, kimai: email }
    connection:
      # This must be absolute and end in /auth/saml/ so IdP URLs are not paths.
      baseurl: 'http://e2e-kimai:8001/auth/saml/'
      debug: true
      idp:
        entityId: 'http://e2e-nextcloud/apps/saml_provider/saml/metadata'
        singleSignOnService:
          url: 'http://e2e-nextcloud/apps/saml_provider/saml/sso'
          binding: 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect'
        x509cert: '${idp_cert}'
      sp:
        entityId: 'http://e2e-kimai:8001/auth/saml/metadata'
        assertionConsumerService:
          url: 'http://e2e-kimai:8001/auth/saml/acs'
          binding: 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST'
YAML
docker run -d --name "$mariadb" --network "$network" -e MARIADB_DATABASE=kimai -e MARIADB_USER=kimai -e MARIADB_PASSWORD=kimai -e MARIADB_ROOT_PASSWORD=root-password "${MARIADB_IMAGE:-mariadb:11.4}" >/dev/null
for attempt in $(seq 1 60); do docker exec "$mariadb" mariadb-admin ping -h localhost -uroot -proot-password --silent && break; [[ "$attempt" == 60 ]] && fail 'MariaDB did not become ready'; sleep 2; done
docker run -d --name "$kimai" --network "$network" -e 'DATABASE_URL=mysql://kimai:kimai@e2e-mariadb:3306/kimai?charset=utf8mb4&serverVersion=mariadb-11.4.0' -e APP_SECRET=kimai-e2e-only -e TRUSTED_HOSTS='e2e-kimai|localhost|127\.0\.0\.1' -e TRUSTED_PROXIES='127.0.0.1,172.16.0.0/12' -v "$workspace/build/e2e/kimai-local.yaml:/opt/kimai/config/packages/local.yaml:ro" "${KIMAI_IMAGE:-kimai/kimai2:apache}" >/dev/null
wait_http http://e2e-kimai:8001/ "$kimai"
kimai_metadata_response="$(docker run --rm --network "$network" curlimages/curl:8.10.1 --silent --show-error --write-out $'\n%{http_code}' http://e2e-kimai:8001/auth/saml/metadata)" || fail 'Could not contact Kimai SAML metadata endpoint'
kimai_metadata_status="${kimai_metadata_response##*$'\n'}"
metadata="${kimai_metadata_response%$'\n'*}"
printf '%s' "$metadata" > build/e2e/browser-artifacts/kimai-saml-metadata-response.txt
if [[ ! "$kimai_metadata_status" =~ ^2[0-9][0-9]$ ]]; then
  printf 'Kimai metadata endpoint returned HTTP %s. Response follows:\n%s\n' "$kimai_metadata_status" "$metadata" >&2
  docker logs "$kimai" >&2 || true
  fail 'Kimai SAML metadata endpoint did not return success'
fi
printf '%s' "$metadata" | grep -q EntityDescriptor || fail 'Kimai SAML metadata response has no EntityDescriptor'
printf '%s' "$metadata" | grep -q 'http://e2e-kimai:8001/auth/saml/acs' || fail 'Kimai metadata has unexpected ACS'
acs_status="$(docker run --rm --network "$network" curlimages/curl:8.10.1 --silent --show-error --output /dev/null --write-out '%{http_code}' -X POST http://e2e-kimai:8001/auth/saml/acs)" || fail 'Could not contact Kimai SAML ACS endpoint'
[[ "$acs_status" != 404 ]] || fail 'Kimai SAML ACS endpoint is disabled'

# Drive the entire user journey in a real browser. No Nextcloud login HTML,
# CSRF representation, form action, or SAML POST form is parsed or replayed.
# Pull before the trace marker: the marker now denotes the actual browser test.
playwright_image="${PLAYWRIGHT_IMAGE:-mcr.microsoft.com/playwright:v1.54.0-noble}"
docker pull "$playwright_image"
mkdir -p "$workspace/build/e2e/browser-artifacts"
# The browser image supplies browsers and OS dependencies, but no importable
# project module. Prepare the matching Node package in a temporary work directory.
playwright_work="$workspace/build/e2e/playwright-work"
rm -rf "$playwright_work"
mkdir -p "$playwright_work"
playwright_setup='cd /work && npm install --no-save --ignore-scripts playwright@1.54.0 '
docker run --rm --user "$(id -u):$(id -g)" \
  --volume "$playwright_work:/work" \
  --env PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
  --env npm_config_cache=/tmp/npm-cache \
  "$playwright_image" \
  sh -ec "$playwright_setup"
echo '================================================================='
echo 'KIMAI SAML BROWSER E2E STARTS - copy logs from this line'
echo '================================================================='
# Run from /work so Node resolves /work/node_modules/playwright. The version
# matches the pinned image, whose Chromium is used without a second download.
docker run --rm --network "$network" --ipc=host --user "$(id -u):$(id -g)" \
  --volume "$playwright_work:/work" \
  --volume "$workspace/tests/E2E/kimai-saml-browser.mjs:/work/kimai-saml-browser.mjs:ro" \
  --volume "$workspace/build/e2e/browser-artifacts:/work/browser-artifacts" \
  --env E2E_ARTIFACT_DIR=/work/browser-artifacts \
  "$playwright_image" \
  node /work/kimai-saml-browser.mjs
rm -rf "$playwright_work"
user_count="$(docker exec "$mariadb" mariadb -N -ukimai -pkimai kimai -e "SELECT COUNT(*) FROM kimai2_users WHERE email = 'admin@example.test' AND auth = 'saml'" 2>/dev/null || true)"
[[ "$user_count" == '1' ]] || fail "Kimai did not import the browser-authenticated SAML user (count: ${user_count:-none})"
echo 'Kimai SAML browser end-to-end test passed.'
completed=true
echo '=========================================================='
echo 'KIMAI SAML BROWSER E2E ENDED SUCCESSFULLY - end of trace'
echo '=========================================================='
