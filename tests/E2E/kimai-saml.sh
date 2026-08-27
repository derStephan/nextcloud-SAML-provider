#!/usr/bin/env bash
# Isolated full SAML interoperability test. It deliberately has no release/App Store secrets.
set -euo pipefail
network=saml-e2e; nextcloud=e2e-nextcloud; kimai=e2e-kimai; mariadb=e2e-mariadb
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
        entityId: 'http://e2e-kimai:8001/auth/saml/metadata'
        assertionConsumerService:
          url: 'http://e2e-kimai:8001/auth/saml/acs'
          binding: 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST'
YAML
docker run -d --name "$mariadb" --network "$network" -e MARIADB_DATABASE=kimai -e MARIADB_USER=kimai -e MARIADB_PASSWORD=kimai -e MARIADB_ROOT_PASSWORD=root-password "${MARIADB_IMAGE:-mariadb:11.4}" >/dev/null
for attempt in $(seq 1 60); do docker exec "$mariadb" mariadb-admin ping -h localhost -uroot -proot-password --silent && break; [[ "$attempt" == 60 ]] && fail 'MariaDB did not become ready'; sleep 2; done
docker run -d --name "$kimai" --network "$network" -e 'DATABASE_URL=mysql://kimai:kimai@e2e-mariadb:3306/kimai?charset=utf8mb4&serverVersion=mariadb-11.4.0' -e APP_SECRET=kimai-e2e-only -e TRUSTED_HOSTS='e2e-kimai|localhost|127\.0\.0\.1' -e TRUSTED_PROXIES='127.0.0.1,172.16.0.0/12' -v "$workspace/build/e2e/kimai-local.yaml:/opt/kimai/config/packages/local.yaml:ro" "${KIMAI_IMAGE:-kimai/kimai2:apache}" >/dev/null
wait_http http://e2e-kimai:8001/ "$kimai"
metadata="$(docker run --rm --network "$network" curlimages/curl:8.10.1 --silent --fail http://e2e-kimai:8001/auth/saml/metadata)"
printf '%s' "$metadata" | grep -q EntityDescriptor || fail 'Kimai SAML metadata unavailable'
printf '%s' "$metadata" | grep -q 'http://e2e-kimai:8001/auth/saml/acs' || fail 'Kimai metadata has unexpected ACS'
status="$(docker run --rm --network "$network" curlimages/curl:8.10.1 --silent --output /dev/null --write-out '%{http_code}' -X POST http://e2e-kimai:8001/auth/saml/acs)"
[[ "$status" != 404 ]] || fail 'Kimai SAML ACS endpoint is disabled'

# Full SP-initiated SSO, driven by a shared cookie jar: Kimai AuthnRequest ->
# Nextcloud login -> signed POST binding -> Kimai ACS -> database user import.
e2e_dir="$workspace/build/e2e"; cookies="$e2e_dir/browser-cookies.txt"
rm -f "$cookies" "$e2e_dir"/{kimai-login,sso,login-submit,acs}.headers "$e2e_dir"/{login,saml-response,acs}.html
# Use the runner numeric UID/GID so cookie, header and response files are writable
# in the shared E2E workspace bind mount on hosted and self-hosted runners alike.
http() { docker run --rm --user "$(id -u):$(id -g)" --network "$network" --volume "$e2e_dir:/work" curlimages/curl:8.10.1 "$@"; }
location() { sed -n 's/^[Ll]ocation: *\(.*\)\r*$/\1/p' "$1" | tail -n 1 | tr -d '\r'; }
absolute_url() { case "$1" in http://*|https://*) printf '%s' "$1" ;; /*) printf '%s%s' "$2" "$1" ;; *) printf '%s/%s' "$2" "$1" ;; esac; }

echo 'Starting full Kimai SSO flow'
http --silent --show-error --dump-header /work/kimai-login.headers --output /dev/null --cookie "/work/$(basename "$cookies")" --cookie-jar "/work/$(basename "$cookies")" http://e2e-kimai:8001/auth/saml/login
sso_url="$(location "$e2e_dir/kimai-login.headers")"; [[ -n "$sso_url" ]] || fail 'Kimai login did not redirect to Nextcloud'
sso_url="$(absolute_url "$sso_url" http://e2e-kimai:8001)"; [[ "$sso_url" == http://e2e-nextcloud/apps/saml_provider/saml/sso\?* ]] || fail "unexpected IdP endpoint: $sso_url"
http --silent --show-error --dump-header /work/sso.headers --output /dev/null --cookie "/work/$(basename "$cookies")" --cookie-jar "/work/$(basename "$cookies")" "$sso_url"
login_url="$(location "$e2e_dir/sso.headers")"; [[ -n "$login_url" ]] || fail 'Nextcloud SSO did not redirect anonymous client to login'
login_url="$(absolute_url "$login_url" http://e2e-nextcloud)"
http --silent --show-error --dump-header /work/login.headers --output /work/login.html --cookie "/work/$(basename "$cookies")" --cookie-jar "/work/$(basename "$cookies")" "$login_url"
# Login controllers can render requesttoken as a hidden field, but the normal login
# endpoint also accepts the credential form without tying this protocol test to a
# particular template/theme. Send the token when present; do not fail merely because a
# Nextcloud version changes the login HTML.
login_flat="$(tr '\n' ' ' < "$e2e_dir/login.html")"
requesttoken="$(printf '%s' "$login_flat" | sed -n 's/.*name=["'"'"']requesttoken["'"'"'][^>]*value=["'"'"']\([^"'"'"' ]*\)["'"'"'].*/\1/p' | head -n 1)"
if [[ -z "$requesttoken" ]]; then
  requesttoken="$(printf '%s' "$login_flat" | sed -n 's/.*value=["'"'"']\([^"'"'"' ]*\)["'"'"'][^>]*name=["'"'"']requesttoken["'"'"'].*/\1/p' | head -n 1)"
fi
login_args=(--silent --show-error --dump-header /work/login-submit.headers --output /work/login-submit.html --cookie "/work/$(basename "$cookies")" --cookie-jar "/work/$(basename "$cookies")" --request POST --data-urlencode 'user=admin' --data-urlencode 'password=integration-test-password')
if [[ -n "$requesttoken" ]]; then
  login_args+=(--data-urlencode "requesttoken=$requesttoken")
fi
http "${login_args[@]}" "$login_url"
return_url="$(location "$e2e_dir/login-submit.headers")"
if [[ -z "$return_url" ]]; then
  echo "--- Nextcloud login page headers ---" >&2
  cat "$e2e_dir/login.headers" >&2 || true
  echo "--- Nextcloud login page excerpt ---" >&2
  head -c 2048 "$e2e_dir/login.html" >&2 || true
  echo >&2
  echo "--- Nextcloud login submit headers ---" >&2
  cat "$e2e_dir/login-submit.headers" >&2 || true
  echo "--- Nextcloud login submit excerpt ---" >&2
  head -c 4096 "$e2e_dir/login-submit.html" >&2 || true
  echo >&2
  fail 'Nextcloud login did not return to pending SSO request'
fi
return_url="$(absolute_url "$return_url" http://e2e-nextcloud)"
http --silent --show-error --dump-header /work/saml-response.headers --output /work/saml-response.html --cookie "/work/$(basename "$cookies")" --cookie-jar "/work/$(basename "$cookies")" "$return_url"
# Parse the generated POST form rather than matching an assumed HTML attribute order.
form_json="$(python3 "$workspace/tests/E2E/extract_saml_post_form.py" "$e2e_dir/saml-response.html")"
acs_url="$(python3 -c 'import json,sys; print(json.load(sys.stdin)["action"])' <<< "$form_json")"
saml_response="$(python3 -c 'import json,sys; print(json.load(sys.stdin)["SAMLResponse"])' <<< "$form_json")"
if [[ "$acs_url" != 'http://e2e-kimai:8001/auth/saml/acs' || -z "$saml_response" ]]; then
  echo "--- SSO return URL ---" >&2
  printf '%s\n' "$return_url" >&2
  echo "--- SSO response headers ---" >&2
  cat "$e2e_dir/saml-response.headers" >&2 || true
  echo "--- SSO response form parse ---" >&2
  printf '%s\n' "$form_json" >&2
  echo "--- SSO response excerpt ---" >&2
  head -c 4096 "$e2e_dir/saml-response.html" >&2 || true
  echo >&2
  fail "Nextcloud did not return the expected SAML POST form (ACS: ${acs_url:-none})"
fi
http --silent --show-error --dump-header /work/acs.headers --output /work/acs.html --cookie "/work/$(basename "$cookies")" --cookie-jar "/work/$(basename "$cookies")" --request POST --data-urlencode "SAMLResponse=$saml_response" "$acs_url"
acs_status="$(awk 'NR == 1 {print $2}' "$e2e_dir/acs.headers" | tr -d '\r')"; [[ "$acs_status" =~ ^30[12378]$ ]] || fail "Kimai rejected signed SAML response (HTTP ${acs_status:-none})"
user_count="$(docker exec "$mariadb" mariadb -N -ukimai -pkimai kimai -e "SELECT COUNT(*) FROM kimai2_users WHERE email = 'admin@example.test' AND auth = 'saml'" 2>/dev/null || true)"
[[ "$user_count" == '1' ]] || fail "Kimai did not import SAML user (count: ${user_count:-none})"
rm -f "$cookies" "$e2e_dir"/{kimai-login,sso,login,login-submit,saml-response,acs}.headers "$e2e_dir"/{login,login-submit,saml-response,acs}.html
completed=true
echo 'Kimai SAML full browser-style SSO test passed.'
