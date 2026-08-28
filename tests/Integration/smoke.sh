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
# Prove requireSignedRequests on the live public SSO endpoint for both SAML bindings.
# The helper provisions an enabled SP with a real certificate and emits well-formed,
# unsigned and OpenSSL-signed AuthnRequests without printing its ephemeral key material.
policy_json="$(mktemp)"
set +e
docker exec --user www-data "$container" php /var/www/html/custom_apps/saml_provider/tests/Integration/prepare-signed-request-policy.php >"$policy_json" 2>&1
policy_status=$?
set -e
if (( policy_status != 0 )); then
  cat "$policy_json" >&2
  rm -f "$policy_json"
  echo "Integration signature-policy preparation failed (exit $policy_status)" >&2
  exit "$policy_status"
fi
read_policy() { python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))[sys.argv[2]])' "$policy_json" "$1"; }
unsigned_post="$(read_policy unsignedPost)"; signed_post="$(read_policy signedPost)"
unsigned_redirect="$(read_policy unsignedRedirect)"; signed_redirect="$(read_policy signedRedirect)"
rm -f "$policy_json"

sso_url="$base_url/apps/saml_provider/saml/sso"
http_status() { curl --silent --show-error --output /dev/null --write-out '%{http_code}' "$@"; }
# Well-formed but unsigned requests must reach and be rejected by the signature policy.
[[ "$(http_status --data-urlencode "SAMLRequest=$unsigned_post" "$sso_url")" == 400 ]] || { echo 'Unsigned POST-binding AuthnRequest was not rejected.' >&2; exit 1; }
[[ "$(http_status "$sso_url?$unsigned_redirect")" == 400 ]] || { echo 'Unsigned Redirect-binding AuthnRequest was not rejected.' >&2; exit 1; }
# Correct signatures must pass policy enforcement and continue to the real Nextcloud login flow.
[[ "$(http_status --data-urlencode "SAMLRequest=$signed_post" "$sso_url")" =~ ^30[23]$ ]] || { echo 'Signed POST-binding AuthnRequest did not reach the Nextcloud login redirect.' >&2; exit 1; }
[[ "$(http_status "$sso_url?$signed_redirect")" =~ ^30[23]$ ]] || { echo 'Signed Redirect-binding AuthnRequest did not reach the Nextcloud login redirect.' >&2; exit 1; }
echo 'NEXTCLOUD LIVE SIGNATURE POLICY CONTRACT: unsigned Redirect and POST requests rejected; signed Redirect and POST requests reached login continuation.'
echo "Nextcloud integration and persistence contracts passed for $NEXTCLOUD_VERSION/$driver"
