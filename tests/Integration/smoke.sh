#!/usr/bin/env bash
set -euo pipefail
base_url="${NEXTCLOUD_URL:-http://127.0.0.1:8080}"
container="${NEXTCLOUD_CONTAINER:-nextcloud-under-test}"
for attempt in $(seq 1 60); do
    if curl --fail --silent --output /dev/null "$base_url/status.php"; then break; fi
    if [[ "$attempt" == "60" ]]; then docker logs "$container" >&2 || true; exit 1; fi
    sleep 2
done
docker exec --user www-data "$container" php occ maintenance:install --database sqlite --database-name nextcloud --admin-user admin --admin-pass integration-test-password --data-dir /var/www/html/data
docker exec --user www-data "$container" php occ app:enable saml_provider
docker exec --user www-data "$container" php occ app:list --output=json | grep -q '"saml_provider"'
metadata_status="$(curl --silent --output /dev/null --write-out '%{http_code}' "$base_url/apps/saml_provider/saml/metadata")"
[[ "$metadata_status" == "404" ]]
sso_status="$(curl --silent --output /dev/null --write-out '%{http_code}' "$base_url/apps/saml_provider/saml/sso")"
[[ "$sso_status" == "400" ]]
echo "Nextcloud integration smoke test passed for $NEXTCLOUD_VERSION"
