#!/usr/bin/env bash
# Isolated SAML interoperability wiring check. It deliberately has no release/App Store secrets.
set -euo pipefail
network=saml-e2e; nextcloud=e2e-nextcloud; kimai=e2e-kimai; mariadb=e2e-mariadb
workspace="${GITHUB_WORKSPACE:-$PWD}"
cleanup(){ docker rm --force "$nextcloud" "$kimai" "$mariadb" 2>/dev/null || true; docker network rm "$network" 2>/dev/null || true; }
trap cleanup EXIT
fail(){ echo "E2E failure: $*" >&2; exit 1; }
wait_http(){ local url="$1" name="$2"; for attempt in $(seq 1 90); do docker run --rm --network "$network" curlimages/curl:8.10.1 --silent --fail --output /dev/null "$url" && return 0; sleep 2; done; docker logs "$name" >&2 || true; fail "Timed out waiting for $url"; }
docker network create "$network" >/dev/null
docker run -d --name "$nextcloud" --network "$network" -v "$workspace:/var/www/html/custom_apps/saml_provider:ro" "${NEXTCLOUD_IMAGE:-nextcloud:34-apache}" >/dev/null
wait_http http://e2e-nextcloud/status.php "$nextcloud"
docker exec --user www-data "$nextcloud" php occ maintenance:install --database sqlite --database-name nextcloud --admin-user admin --admin-pass integration-test-password --data-dir /var/www/html/data >/dev/null
docker exec --user www-data "$nextcloud" php occ config:system:set overwrite.cli.url --value=http://e2e-nextcloud >/dev/null
docker exec --user www-data "$nextcloud" php occ app:enable saml_provider >/dev/null
# A real SQL query makes a missing Migration fail this E2E test.
docker exec --user www-data "$nextcloud" php -r '
$db = new PDO("sqlite:/var/www/html/data/nextcloud.db");
$name = $db->query("SELECT name FROM sqlite_master WHERE type=\"table\" AND name=\"oc_saml_provider_sp\"")->fetchColumn();
if ($name !== "oc_saml_provider_sp") { fwrite(STDERR, "missing migration table\n"); exit(1); }
' || fail 'saml_provider_sp migration table is unavailable'
# Test-only self-signed IdP material. It exists only inside this ephemeral Docker container.
docker exec --user www-data "$nextcloud" sh -ec 'openssl req -x509 -newkey rsa:2048 -nodes -keyout /tmp/idp.key -out /tmp/idp.crt -subj "/CN=e2e-nextcloud" -days 1 >/dev/null 2>&1; php occ config:app:set saml_provider idp_certificate --value="$(cat /tmp/idp.crt)"; php occ config:app:set saml_provider idp_private_key --value="$(cat /tmp/idp.key)"'
idp_cert="$(docker exec --user www-data "$nextcloud" php occ config:app:get saml_provider idp_certificate | awk 'BEGIN{ORS=""} !/BEGIN CERTIFICATE|END CERTIFICATE/{gsub(/[[:space:]]/,"");print}')"
[[ -n "$idp_cert" ]] || fail 'IdP certificate was not stored'
wait_http http://e2e-nextcloud/apps/saml_provider/saml/metadata "$nextcloud"
mkdir -p build/e2e
cat > build/e2e/kimai-local.yaml <<YAML
kimai:
  saml:
    provider: nextcloud
    activate: true
    title: Login with Nextcloud
    mapping:
      - { saml: \$Email, kimai: email }
    connection:
      idp:
        entityId: 'http://e2e-nextcloud/apps/saml_provider/saml/metadata'
        singleSignOnService:
          url: 'http://e2e-nextcloud/apps/saml_provider/saml/sso'
          binding: 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect'
        x509cert: '${idp_cert}'
      sp:
        entityId: 'http://e2e-kimai/auth/saml/metadata'
        assertionConsumerService:
          url: 'http://e2e-kimai/auth/saml/acs'
          binding: 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST'
YAML
docker run -d --name "$mariadb" --network "$network" -e MARIADB_DATABASE=kimai -e MARIADB_USER=kimai -e MARIADB_PASSWORD=kimai -e MARIADB_ROOT_PASSWORD=root-password "${MARIADB_IMAGE:-mariadb:11.4}" >/dev/null
for attempt in $(seq 1 60); do docker exec "$mariadb" mariadb-admin ping -h localhost -uroot -proot-password --silent && break; [[ "$attempt" == 60 ]] && fail 'MariaDB did not become ready'; sleep 2; done
docker run -d --name "$kimai" --network "$network" -e 'DATABASE_URL=mysql://kimai:kimai@e2e-mariadb:3306/kimai?charset=utf8mb4&serverVersion=mariadb-11.4.0' -e APP_SECRET=kimai-e2e-only -e TRUSTED_HOSTS=e2e-kimai -e TRUSTED_PROXIES='127.0.0.1,172.16.0.0/12' -v "$workspace/build/e2e/kimai-local.yaml:/opt/kimai/config/packages/local.yaml:ro" "${KIMAI_IMAGE:-kimai/kimai2:apache}" >/dev/null
wait_http http://e2e-kimai/ "$kimai"
metadata="$(docker run --rm --network "$network" curlimages/curl:8.10.1 --silent --fail http://e2e-kimai/auth/saml/metadata)"
printf '%s' "$metadata" | grep -q EntityDescriptor || fail 'Kimai SAML metadata unavailable'
printf '%s' "$metadata" | grep -q 'http://e2e-kimai/auth/saml/acs' || fail 'Kimai metadata has unexpected ACS'
status="$(docker run --rm --network "$network" curlimages/curl:8.10.1 --silent --output /dev/null --write-out '%{http_code}' -X POST http://e2e-kimai/auth/saml/acs)"
[[ "$status" != 404 ]] || fail 'Kimai SAML ACS endpoint is disabled'
echo 'Kimai SAML E2E wiring passed.'
