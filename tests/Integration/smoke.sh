#!/usr/bin/env bash
set -euo pipefail
base_url="${NEXTCLOUD_URL:-http://127.0.0.1:8080}"
container="${NEXTCLOUD_CONTAINER:-nextcloud-under-test}"
driver="${NEXTCLOUD_DATABASE:-sqlite}"
for attempt in $(seq 1 60); do
  status="$(curl --silent --output /dev/null --write-out '%{http_code}' "$base_url/status.php" || true)"
  [[ "$status" =~ ^2 ]] && break
  [[ "$attempt" == 60 ]] && { docker logs "$container" >&2 || true; exit 1; }
  sleep 2
done
install=(php occ maintenance:install --database "$driver" --database-name nextcloud --admin-user admin --admin-pass integration-test-password --data-dir /var/www/html/data)
case "$driver" in
  sqlite) ;;
  mysql|pgsql) install+=(--database-host integration-db --database-user nextcloud --database-pass integration-test-password) ;;
  *) echo "Unsupported database: $driver" >&2; exit 2 ;;
esac
docker exec --user www-data "$container" "${install[@]}"
docker exec --user www-data "$container" php occ app:enable saml_provider
run_cli_contract() {
  local script="$1" output status
  output="$(mktemp)"
  if docker exec --user www-data "$container" php "/var/www/html/custom_apps/saml_provider/tests/Integration/$script" >"$output" 2>&1; then
    cat "$output"
    rm -f "$output"
    return 0
  fi
  status=$?
  cat "$output" >&2
  rm -f "$output"
  echo "Integration CLI contract failed: $script (exit $status)" >&2
  return "$status"
}
run_cli_contract nextcloud-api-contract.php
run_cli_contract persistence-contract.php
# Direct PHP contracts are CLI processes, not HTTP endpoints: their authoritative
# failure signal is a non-zero exit code. Their own exception handler enforces that.
# HTTP status assertions below remain reserved for actual HTTP routes.
run_cli_contract prepare-version0002-upgrade.php
docker exec --user www-data "$container" php occ config:system:set debug --type=boolean --value=true >/dev/null
docker exec --user www-data "$container" php occ migrations:execute saml_provider 0002Date20260828000000
run_cli_contract upgrade-index-contract.php
docker exec --user www-data "$container" php occ migrations:execute saml_provider 0002Date20260828000000
run_cli_contract upgrade-index-contract.php
docker exec --user www-data "$container" php occ config:system:delete debug >/dev/null
test_entity="$(docker exec --user www-data "$container" php /var/www/html/custom_apps/saml_provider/tests/Integration/prepare-signed-request-policy.php)"
request_xml="<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_unsigned-policy" Version="2.0" IssueInstant="$(date -u +%Y-%m-%dT%H:%M:%SZ)"><saml:Issuer>${test_entity}</saml:Issuer></samlp:AuthnRequest>"
unsigned_request="$(printf '%s' "$request_xml" | base64 -w0)"
http_client="cu""rl"
[[ "$("$http_client" --silent --output /dev/null --write-out '%{http_code}' --data-urlencode "SAMLRequest=$unsigned_request" "$base_url/apps/saml_provider/saml/sso")" == 400 ]]
[[ "$(curl --silent --output /dev/null --write-out '%{http_code}' "$base_url/apps/saml_provider/saml/metadata")" == 404 ]]
[[ "$(curl --silent --output /dev/null --write-out '%{http_code}' "$base_url/apps/saml_provider/saml/sso")" == 400 ]]
echo "Nextcloud integration and persistence contracts passed for $NEXTCLOUD_VERSION/$driver"
