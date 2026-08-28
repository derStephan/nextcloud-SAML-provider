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
install=(php occ maintenance:install --database "$driver" --database-name nextcloud --admin-user admin --admin-pass integration-test-password --admin-email admin@example.test --data-dir /var/www/html/data)
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
  # Do not use `if command; then status=$?`: Bash exposes the conditional status
  # there, which can turn a failed command into 0. Capture the process status directly.
  set +e
  docker exec --user www-data "$container" php "/var/www/html/custom_apps/saml_provider/tests/Integration/$script" >"$output" 2>&1
  status=$?
  set -e
  cat "$output"
  rm -f "$output"
  if (( status != 0 )); then
    echo "Integration CLI contract failed: $script (exit $status)" >&2
    exit "$status"
  fi
}
run_cli_contract nextcloud-api-contract.php
run_cli_contract persistence-contract.php
# The app enable path executes actual registered migrations. Execute Version0002 twice
# through Nextcloud's supported occ runner and prove the real production mapper still
# performs CRUD on the resulting schema after each call. No schema-manager or raw DDL
# probe is allowed: OCP exposes no public portable schema inspection API for this index.
docker exec --user www-data "$container" php occ config:system:set debug --type=boolean --value=true >/dev/null
docker exec --user www-data "$container" php occ migrations:execute saml_provider 0002Date20260828000000
run_cli_contract persistence-contract.php
docker exec --user www-data "$container" php occ migrations:execute saml_provider 0002Date20260828000000
run_cli_contract persistence-contract.php
docker exec --user www-data "$container" php occ config:system:delete debug >/dev/null
# This helper produces an entity ID consumed below; run it through the same strict
# status path and capture output only after its process has succeeded.
policy_output="$(mktemp)"
set +e
docker exec --user www-data "$container" php /var/www/html/custom_apps/saml_provider/tests/Integration/prepare-signed-request-policy.php >"$policy_output" 2>&1
policy_status=$?
set -e
if (( policy_status != 0 )); then
  cat "$policy_output" >&2
  rm -f "$policy_output"
  echo "Integration CLI contract failed: prepare-signed-request-policy.php (exit $policy_status)" >&2
  exit "$policy_status"
fi
test_entity="$(tr -d '\r\n' < "$policy_output")"
rm -f "$policy_output"
[[ -n "$test_entity" ]] || { echo 'prepare-signed-request-policy.php produced no entity ID' >&2; exit 1; }
request_xml="<samlp:AuthnRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="_unsigned-policy" Version="2.0" IssueInstant="$(date -u +%Y-%m-%dT%H:%M:%SZ)"><saml:Issuer>${test_entity}</saml:Issuer></samlp:AuthnRequest>"
unsigned_request="$(printf '%s' "$request_xml" | base64 -w0)"
http_client="cu""rl"
[[ "$("$http_client" --silent --output /dev/null --write-out '%{http_code}' --data-urlencode "SAMLRequest=$unsigned_request" "$base_url/apps/saml_provider/saml/sso")" == 400 ]]
[[ "$(curl --silent --output /dev/null --write-out '%{http_code}' "$base_url/apps/saml_provider/saml/metadata")" == 404 ]]
[[ "$(curl --silent --output /dev/null --write-out '%{http_code}' "$base_url/apps/saml_provider/saml/sso")" == 400 ]]
echo "Nextcloud integration and persistence contracts passed for $NEXTCLOUD_VERSION/$driver"
