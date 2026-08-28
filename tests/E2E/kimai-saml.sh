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
# Docker startup chatter is not actionable in a failed test. Keep explicit error,
# warning and exception lines, but omit routine lifecycle messages from diagnostics.
print_docker_failure_logs(){
  local name="$1"
  docker logs "$name" 2>&1 | awk '    BEGIN { IGNORECASE=1 }
    /error|fatal|exception|critical|panic|fail(ed|ure)?|warn(ing)?/ { print; next }
    /(^|[[:space:]])(completed|verified|ready|started|healthy|initialized|initializing)([[:space:].,:;]|$)/ { next }
    /layer already exists|pull complete|download complete|digest:|status: downloaded/ { next }
    { print }
  ' || true
}
pull_image_quietly(){
  local image="$1" pull_log
  pull_log="$(mktemp)"
  if ! docker pull --quiet "$image" >"$pull_log" 2>&1; then
    echo "Failed to pull required container image: $image" >&2
    cat "$pull_log" >&2
    rm -f "$pull_log"
    fail "Container image pull failed: $image"
  fi
  rm -f "$pull_log"
  echo "Container image ready: $image"
}
wait_http(){
  local url="$1" name="$2" status
  for attempt in $(seq 1 90); do
    status="$(docker run --rm --network "$network" "$curl_image" --silent --output /dev/null --write-out '%{http_code}' "$url" || true)"
    # Kimai correctly redirects anonymous requests (302) to its login page. Readiness
    # means the application answered; endpoint-specific assertions below remain strict.
    if [[ "$status" =~ ^[123][0-9]{2}$ ]]; then return 0; fi
    sleep 2
  done
  print_docker_failure_logs "$name"
  fail "Timed out waiting for $url (last HTTP status: ${status:-none})"
}
bash "$workspace/tests/Integration/print-test-contract.sh"
# Pull every image before it is first run. Quiet pulls prevent Docker layer-progress
# noise from obscuring useful test diagnostics.
curl_image="${CURL_IMAGE:-curlimages/curl:8.10.1}"
nextcloud_image="${NEXTCLOUD_IMAGE:-nextcloud:34-apache}"
kimai_image="${KIMAI_IMAGE:-kimai/kimai2:apache}"
mariadb_image="${MARIADB_IMAGE:-mariadb:11.4}"
playwright_image="${PLAYWRIGHT_IMAGE:-mcr.microsoft.com/playwright:v1.62.1-noble}"
for image in "$curl_image" "$nextcloud_image" "$mariadb_image" "$kimai_image" "$playwright_image"; do pull_image_quietly "$image"; done

docker network create "$network" >/dev/null
docker run -d --name "$nextcloud" --network "$network" -v "$workspace:/var/www/html/custom_apps/saml_provider:ro" "$nextcloud_image" >/dev/null
wait_http http://e2e-nextcloud/status.php "$nextcloud"
docker exec --user www-data "$nextcloud" php occ maintenance:install --database sqlite --database-name nextcloud --admin-user admin --admin-pass integration-test-password --admin-email admin@example.test --data-dir /var/www/html/data >/dev/null
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
# Kimai consumes the certificate as a single base64 line without PEM markers.
# Its 2.65 SAML bundle requires the nested connection.idp/connection.sp schema;
# an old flat connection schema silently leaves the SAML routes unregistered.
certificate="$(python3 -c 'import json,re,sys; c=json.load(open(sys.argv[1]))["certificate"]; c=re.sub(r"-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----", "", c); print(re.sub(r"\s+", "", c))' "$kimai_idp_json")"
[[ "$certificate" =~ ^[A-Za-z0-9+/=]+$ ]] || fail 'Admin browser setup produced an invalid IdP certificate body'
# Public IdP metadata must work after the certificate was created through the real
# admin UI. This is HTTP evidence, not a source inspection.
metadata_response="$(docker run --rm --network "$network" "$curl_image" --silent --show-error --write-out $'\n%{http_code}\n%{content_type}' http://e2e-nextcloud/apps/saml_provider/saml/metadata)" || fail 'Could not fetch Nextcloud IdP metadata'
metadata_type="${metadata_response##*$'\n'}"; metadata_response="${metadata_response%$'\n'*}"
metadata_status="${metadata_response##*$'\n'}"; metadata="${metadata_response%$'\n'*}"
[[ "$metadata_status" == 200 ]] || fail "Generated IdP metadata returned HTTP $metadata_status, expected 200"
[[ "$metadata_type" == application/samlmetadata+xml* ]] || fail "Generated IdP metadata returned unexpected content type: $metadata_type"
printf '%s' "$metadata" | python3 -c 'import sys,xml.etree.ElementTree as E; E.fromstring(sys.stdin.read())' || fail 'Generated IdP metadata is not well-formed XML'
printf '%s' "$metadata" | grep -Fq 'entityID="http://e2e-nextcloud/apps/saml_provider/saml/metadata"' || fail 'Metadata EntityID is wrong'
printf '%s' "$metadata" | grep -Fq 'Location="http://e2e-nextcloud/apps/saml_provider/saml/sso"' || fail 'Metadata SSO URL is wrong'
printf '%s' "$metadata" | grep -Fq "$certificate" || fail 'Metadata does not publish the generated signing certificate'
# Both unspecified NameID namespace variants must be accepted by the *running*
# public SSO endpoint. Persisted configuration is independently exported by the browser
# setup; refuse to probe if the saved Entity ID or ACS URL is not the expected product state.
persisted_entity="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["persisted"]["entityId"])' "$kimai_idp_json")"
persisted_acs="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["persisted"]["acsUrl"])' "$kimai_idp_json")"
persisted_nameid="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["persisted"]["nameIdFormat"])' "$kimai_idp_json")"
[[ "$persisted_entity" == 'http://e2e-kimai:8001/auth/saml/metadata' ]] || fail "Persisted Kimai Entity ID is unexpected: $persisted_entity"
[[ "$persisted_acs" == 'http://e2e-kimai:8001/auth/saml/acs' ]] || fail "Persisted Kimai ACS URL is unexpected: $persisted_acs"
[[ "$persisted_nameid" == 'urn:oasis:names:tc:SAML:2.0:nameid-format:unspecified' ]] || fail "Persisted Kimai NameID format is unexpected: $persisted_nameid"

probe_sso_login_redirect() {
  local label="$1" request_xml="$2" request_file header_file body_file status payload
  request_file="$workspace/build/e2e/browser-artifacts/sso-${label}-request.xml"
  header_file="$workspace/build/e2e/browser-artifacts/sso-${label}-response-headers.txt"
  body_file="$workspace/build/e2e/browser-artifacts/sso-${label}-response-body.html"
  printf '%s' "$request_xml" > "$request_file"
  # This is the real SAML HTTP-Redirect binding used by Kimai: raw DEFLATE then base64.
  # A POST probe cannot prove that an anonymous-login redirect retains the request body.
  payload="$(printf '%s' "$request_xml" | python3 -c 'import base64,sys,zlib; raw=sys.stdin.buffer.read(); c=zlib.compress(raw); print(base64.b64encode(c[2:-4]).decode())')"
  status="$(docker run --rm --network "$network" --user "$(id -u):$(id -g)" \
    --volume "$workspace/build/e2e/browser-artifacts:/artifacts" \
    "$curl_image" --silent --show-error \
    --output "/artifacts/$(basename "$body_file")" \
    --dump-header "/artifacts/$(basename "$header_file")" \
    --write-out '%{http_code}' --get --data-urlencode "SAMLRequest=$payload" \
    http://e2e-nextcloud/apps/saml_provider/saml/sso)" || fail "Could not send SSO login probe $label"
  if [[ ! "$status" =~ ^30[23]$ ]]; then
    printf 'SSO login probe %s expected HTTP 302 or 303, received HTTP %s.\n' "$label" "$status" >&2
    cat "$header_file" >&2 || true
    head -c 4000 "$body_file" >&2 || true
    print_docker_failure_logs "$nextcloud"
    fail "Running SSO endpoint did not redirect accepted request $label to Nextcloud login"
  fi
  python3 - "$header_file" <<'PY'
from pathlib import Path
from urllib.parse import parse_qs, urlparse
import sys
headers = Path(sys.argv[1]).read_text(errors='replace').replace('\r\n', '\n')
locations = [line.split(':', 1)[1].strip() for line in headers.splitlines() if line.lower().startswith('location:')]
if len(locations) != 1:
    raise SystemExit(f'Expected exactly one Location header, got {locations!r}')
location = locations[0]
parsed = urlparse(location)
if parsed.scheme != 'http' or parsed.netloc != 'e2e-nextcloud' or parsed.path != '/login':
    raise SystemExit(f'Accepted SSO request redirected somewhere other than Nextcloud login: {location}')
redirect = parse_qs(parsed.query).get('redirect_url', [''])[0]
redirect_url = urlparse(redirect)
if redirect_url.path != '/apps/saml_provider/saml/sso' or 'SAMLRequest' not in parse_qs(redirect_url.query):
    raise SystemExit(f'Login redirect does not preserve the SAML HTTP-Redirect request: {location}')
PY
}

probe_sso_rejection() {
  local label="$1" request_xml="$2" request_file header_file body_file status
  request_file="$workspace/build/e2e/browser-artifacts/sso-${label}-request.xml"
  header_file="$workspace/build/e2e/browser-artifacts/sso-${label}-response-headers.txt"
  body_file="$workspace/build/e2e/browser-artifacts/sso-${label}-response-body.html"
  printf '%s' "$request_xml" > "$request_file"
  status="$(printf '%s' "$request_xml" | base64 -w0 | docker run -i --rm --network "$network" \
    --user "$(id -u):$(id -g)" --volume "$workspace/build/e2e/browser-artifacts:/artifacts" \
    "$curl_image" --silent --show-error --output "/artifacts/$(basename "$body_file")" \
    --dump-header "/artifacts/$(basename "$header_file")" --write-out '%{http_code}' \
    --data-urlencode 'SAMLRequest@-' http://e2e-nextcloud/apps/saml_provider/saml/sso)" || fail "Could not send rejected SSO probe $label"
  if [[ "$status" != 400 ]]; then
    printf 'Rejected SSO probe %s expected HTTP 400, received HTTP %s.\n' "$label" "$status" >&2
    cat "$header_file" >&2 || true
    head -c 4000 "$body_file" >&2 || true
    print_docker_failure_logs "$nextcloud"
    fail "Running SSO endpoint did not reject unsupported request $label"
  fi
}

# Labels are deliberately fixed and filesystem-safe. URNs contain colons and must
# never be used as artifact file names because GitHub preserves cross-platform names.
for probe_label in nameid-unspecified-saml11 nameid-unspecified-saml20; do
  case "$probe_label" in
    nameid-unspecified-saml11) nameid_urn='urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified' ;;
    nameid-unspecified-saml20) nameid_urn='urn:oasis:names:tc:SAML:2.0:nameid-format:unspecified' ;;
    *) fail "Unknown fixed NameID probe label: $probe_label" ;;
  esac
  now="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  request_xml="<samlp:AuthnRequest xmlns:samlp=\"urn:oasis:names:tc:SAML:2.0:protocol\" xmlns:saml=\"urn:oasis:names:tc:SAML:2.0:assertion\" ID=\"_nameid$(date +%s%N)\" Version=\"2.0\" IssueInstant=\"$now\" AssertionConsumerServiceURL=\"$persisted_acs\"><saml:Issuer>$persisted_entity</saml:Issuer><samlp:NameIDPolicy Format=\"$nameid_urn\"/></samlp:AuthnRequest>"
  probe_sso_login_redirect "$probe_label" "$request_xml"
done
unsupported_urn='urn:example:unsupported-nameid-format'
now="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
unsupported_xml="<samlp:AuthnRequest xmlns:samlp=\"urn:oasis:names:tc:SAML:2.0:protocol\" xmlns:saml=\"urn:oasis:names:tc:SAML:2.0:assertion\" ID=\"_unsupported$(date +%s%N)\" Version=\"2.0\" IssueInstant=\"$now\" AssertionConsumerServiceURL=\"$persisted_acs\"><saml:Issuer>$persisted_entity</saml:Issuer><samlp:NameIDPolicy Format=\"$unsupported_urn\"/></samlp:AuthnRequest>"
probe_sso_rejection unsupported-nameid "$unsupported_xml"
echo 'NEXTCLOUD LIVE PROTOCOL CONTRACT: persisted service, metadata, supported unspecified NameID formats, and unsupported NameID rejection passed.'
cat > build/e2e/kimai-local.yaml <<YAML
kimai:
  # This dynamically pulled image has unrelated new-user onboarding; disable it so
  # browser evidence remains focused on the SAML authentication contract.
  user:
    wizard: false
  saml:
    provider: nextcloud
    activate: true
    title: Sign in with Nextcloud
    mapping:
      - { saml: \$mail, kimai: email }
      - { saml: \$displayName, kimai: alias }
    connection:
      idp:
        entityId: 'http://e2e-nextcloud/apps/saml_provider/saml/metadata'
        singleSignOnService:
          url: 'http://e2e-nextcloud/apps/saml_provider/saml/sso'
          binding: 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect'
        x509cert: '$certificate'
      sp:
        entityId: 'http://e2e-kimai:8001/auth/saml/metadata'
        assertionConsumerService:
          url: 'http://e2e-kimai:8001/auth/saml/acs'
          binding: 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST'
        singleLogoutService:
          url: 'http://e2e-kimai:8001/auth/saml/logout'
          binding: 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect'
      baseurl: 'http://e2e-kimai:8001/auth/saml/'
      strict: true
      security:
        authnRequestsSigned: false
        # These are security assertions, not compatibility conveniences: the E2E
        # must prove that Kimai validates the signed Response and signed Assertion.
        wantAssertionsSigned: true
        wantMessagesSigned: true
YAML
docker run -d --name "$mariadb" --network "$network" -e MARIADB_DATABASE=kimai -e MARIADB_USER=kimai -e MARIADB_PASSWORD=kimai -e MARIADB_ROOT_PASSWORD=root-password "$mariadb_image" >/dev/null
for attempt in $(seq 1 60); do docker exec "$mariadb" mariadb-admin ping -h localhost -uroot -proot-password --silent >/dev/null && break; [[ "$attempt" == 60 ]] && fail 'MariaDB did not become ready'; sleep 2; done
docker run -d --name "$kimai" --network "$network" -e 'DATABASE_URL=mysql://kimai:kimai@e2e-mariadb:3306/kimai?charset=utf8mb4&serverVersion=11.4.0-MariaDB' -e APP_SECRET=kimai-e2e-only -e TRUSTED_HOSTS='e2e-kimai|localhost|127\\.0\\.0\\.1' -e TRUSTED_PROXIES='127.0.0.1,172.16.0.0/12' -v "$workspace/build/e2e/kimai-local.yaml:/opt/kimai/config/packages/local.yaml:ro" "$kimai_image" >/dev/null
wait_http http://e2e-kimai:8001/ "$kimai"
# The root route can answer before Kimai has finished warming the SAML subsystem.
# Retry the exact public metadata contract, not merely the root readiness endpoint.
kimai_metadata_status='none'
metadata=''
for attempt in $(seq 1 60); do
  kimai_metadata_response="$(docker run --rm --network "$network" "$curl_image" --silent --show-error --write-out $'\n%{http_code}' http://e2e-kimai:8001/auth/saml/metadata)" || true
  kimai_metadata_status="${kimai_metadata_response##*$'\n'}"
  metadata="${kimai_metadata_response%$'\n'*}"
  printf '%s' "$metadata" > build/e2e/browser-artifacts/kimai-saml-metadata-response.txt
  if [[ "$kimai_metadata_status" =~ ^2[0-9][0-9]$ ]] && printf '%s' "$metadata" | grep -Fq 'http://e2e-kimai:8001/auth/saml/acs'; then
    break
  fi
  [[ "$attempt" == 60 ]] && { print_docker_failure_logs "$kimai"; fail "Kimai SAML metadata contract did not become ready (last HTTP status: $kimai_metadata_status)"; }
  sleep 2
done
kimai_login_headers="$(docker run --rm --network "$network" "$curl_image" --silent --show-error --head http://e2e-kimai:8001/auth/saml/login)" || fail 'Could not contact Kimai SAML login endpoint'
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

echo 'Running positive signature-enforcing IdP authentication test'
run_browser positive
echo 'Kimai validated the signed SAML Response and Assertion and opened a protected session.'

echo 'Running tampered signed-response rejection test'
run_browser tampered
echo 'Kimai rejected the browser-tampered signed SAML response without establishing a session.'

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
