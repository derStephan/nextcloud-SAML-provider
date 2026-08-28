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
# Prove requireSignedRequests with valid XML, real key material and both bindings.
policy_output="$(mktemp)"
set +e
docker exec --user www-data "$container" php /var/www/html/custom_apps/saml_provider/tests/Integration/prepare-signed-request-policy.php >"$policy_output" 2>&1
policy_status=$?
set -e
if (( policy_status != 0 )); then cat "$policy_output" >&2; rm -f "$policy_output"; echo "Integration signature-policy helper failed (exit $policy_status)" >&2; exit "$policy_status"; fi
policy_json="$(cat "$policy_output")"; rm -f "$policy_output"
test_entity="$(python3 -c 'import json,sys; print(json.load(sys.stdin)["entityId"])' <<<"$policy_json")"
policy_private_key="$(python3 -c 'import base64,json,sys; print(base64.b64decode(json.load(sys.stdin)["privateKey"]).decode(), end="")' <<<"$policy_json")"
[[ -n "$test_entity" && -n "$policy_private_key" ]] || { echo 'Signature policy helper produced incomplete key material' >&2; exit 1; }
request_xml="<samlp:AuthnRequest xmlns:samlp=\"urn:oasis:names:tc:SAML:2.0:protocol\" xmlns:saml=\"urn:oasis:names:tc:SAML:2.0:assertion\" ID=\"_policy$(date +%s%N)\" Version=\"2.0\" IssueInstant=\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\" AssertionConsumerServiceURL=\"https://sp.example.test/acs\"><saml:Issuer>${test_entity}</saml:Issuer></samlp:AuthnRequest>"
unsigned_request="$(printf '%s' "$request_xml" | base64 -w0)"
# Both are valid XML but unsigned: HTTP 400 proves they reach and fail policy enforcement.
[[ "$(curl --silent --output /dev/null --write-out '%{http_code}' --get --data-urlencode "SAMLRequest=$unsigned_request" "$base_url/apps/saml_provider/saml/sso")" == 400 ]]
[[ "$(curl --silent --output /dev/null --write-out '%{http_code}' --data-urlencode "SAMLRequest=$unsigned_request" "$base_url/apps/saml_provider/saml/sso")" == 400 ]]
# Redirect signing uses exactly the URL-encoded SAML Redirect signature input.
redirect_query="$(REQUEST_XML="$request_xml" PRIVATE_KEY="$policy_private_key" python3 - <<'PY2'
import base64, os, subprocess, tempfile, urllib.parse, zlib
xml=os.environ['REQUEST_XML'].encode(); key=os.environ['PRIVATE_KEY'].encode()
encoded=base64.b64encode(zlib.compress(xml)[2:-4]).decode()
parts=[('SAMLRequest', encoded), ('RelayState', 'signature-policy-contract'), ('SigAlg', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256')]
signed='&'.join(f'{k}={urllib.parse.quote(v,safe="~")}' for k,v in parts)
with tempfile.NamedTemporaryFile() as f:
 f.write(key); f.flush(); signature=subprocess.check_output(['openssl','dgst','-sha256','-sign',f.name],input=signed.encode())
print(signed+'&Signature='+urllib.parse.quote(base64.b64encode(signature).decode(),safe='~'))
PY2
)"
redirect_status="$(curl --silent --output /dev/null --write-out '%{http_code}' "$base_url/apps/saml_provider/saml/sso?$redirect_query")"
[[ "$redirect_status" =~ ^30[23]$ ]] || { echo "Signed Redirect AuthnRequest did not pass signature policy (HTTP $redirect_status)" >&2; exit 1; }
# POST binding uses XMLDSig with exactly the exclusive-C14N/enveloped profile accepted by SignatureService.
signed_post_request="$(REQUEST_XML="$request_xml" PRIVATE_KEY="$policy_private_key" python3 - <<'PY2'
import base64, hashlib, os, subprocess, tempfile
from lxml import etree
root=etree.fromstring(os.environ['REQUEST_XML'].encode()); ds='{http://www.w3.org/2000/09/xmldsig#}'; rid=root.get('ID')
sig=etree.SubElement(root,ds+'Signature',nsmap={'ds':ds[1:-1]}); si=etree.SubElement(sig,ds+'SignedInfo')
etree.SubElement(si,ds+'CanonicalizationMethod',Algorithm='http://www.w3.org/2001/10/xml-exc-c14n#'); etree.SubElement(si,ds+'SignatureMethod',Algorithm='http://www.w3.org/2001/04/xmldsig-more#rsa-sha256')
ref=etree.SubElement(si,ds+'Reference',URI='#'+rid); transforms=etree.SubElement(ref,ds+'Transforms'); etree.SubElement(transforms,ds+'Transform',Algorithm='http://www.w3.org/2000/09/xmldsig#enveloped-signature'); etree.SubElement(transforms,ds+'Transform',Algorithm='http://www.w3.org/2001/10/xml-exc-c14n#'); etree.SubElement(ref,ds+'DigestMethod',Algorithm='http://www.w3.org/2001/04/xmlenc#sha256')
clone=etree.fromstring(etree.tostring(root)); clone.remove(clone.find('ds:Signature',{'ds':ds[1:-1]})); etree.SubElement(ref,ds+'DigestValue').text=base64.b64encode(hashlib.sha256(etree.tostring(clone,method='c14n',exclusive=True)).digest()).decode()
with tempfile.NamedTemporaryFile() as f:
 f.write(os.environ['PRIVATE_KEY'].encode()); f.flush(); value=subprocess.check_output(['openssl','dgst','-sha256','-sign',f.name],input=etree.tostring(si,method='c14n',exclusive=True))
etree.SubElement(sig,ds+'SignatureValue').text=base64.b64encode(value).decode(); print(base64.b64encode(etree.tostring(root)).decode())
PY2
)"
post_status="$(curl --silent --output /dev/null --write-out '%{http_code}' --data-urlencode "SAMLRequest=$signed_post_request" "$base_url/apps/saml_provider/saml/sso")"
[[ "$post_status" =~ ^30[23]$ ]] || { echo "Signed POST AuthnRequest did not pass signature policy (HTTP $post_status)" >&2; exit 1; }
echo 'NEXTCLOUD SIGNATURE POLICY CONTRACT: valid unsigned Redirect and POST requests were rejected; real signed Redirect and POST requests passed requireSignedRequests.'
echo "Nextcloud integration and persistence contracts passed for $NEXTCLOUD_VERSION/$driver"
