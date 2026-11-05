#!/usr/bin/env bash
# Disposable CI-only HTTPS gateway test. Never use production data or expose an Internet listener.
set -euo pipefail

for required in docker curl openssl; do
  command -v "$required" >/dev/null || { echo "Missing CI prerequisite: $required" >&2; exit 1; }
done

id="${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}-$$"
network="goreecloud-dav-tls-net-${id}"
backend="goreecloud-dav-tls-backend-${id}"
proxy="goreecloud-dav-tls-proxy-${id}"
volume="goreecloud-dav-tls-data-${id}"
image="goreecloud-dav:tls-smoke-${id}"
port="${DAV_TLS_SMOKE_PORT:-18443}"
workdir="$(mktemp -d)"
url="https://dav.test:${port}"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cleanup() {
  docker rm -f "$proxy" "$backend" >/dev/null 2>&1 || true
  docker volume rm -f "$volume" >/dev/null 2>&1 || true
  docker network rm "$network" >/dev/null 2>&1 || true
  rm -rf "$workdir"
}
trap cleanup EXIT

# A self-signed, short-lived certificate is used only for CI. Never save its private key.
openssl req -x509 -newkey rsa:2048 -sha256 -nodes -days 1 \
  -keyout "$workdir/tls.key" -out "$workdir/tls.crt" \
  -subj '/CN=dav.test' -addext 'subjectAltName=DNS:dav.test' \
  >"$workdir/openssl.log" 2>&1
chmod 0600 "$workdir/tls.key"

docker build --tag "$image" "$root" >/dev/null
docker network create "$network" >/dev/null
docker volume create "$volume" >/dev/null

start_backend() {
  local trusted_proxy="$1"
  docker run --detach --name "$backend" \
    --network "$network" --network-alias goreecloud-dav-tls-backend \
    --read-only --tmpfs /tmp:rw,noexec,nosuid,size=64m \
    --tmpfs /run/apache2:rw,nosuid,size=8m \
    --tmpfs /var/lock/apache2:rw,nosuid,size=8m \
    --security-opt no-new-privileges:true \
    --cap-drop ALL --cap-add NET_BIND_SERVICE --cap-add SETUID --cap-add SETGID \
    --env DAV_REQUIRE_HTTPS=1 --env "DAV_TRUSTED_PROXIES=$trusted_proxy" \
    --mount "type=volume,src=${volume},dst=/var/lib/goreecloud-dav" \
    "$image" >/dev/null
  # Never publish the plaintext HTTP port from the backend.
  [[ -z "$(docker port "$backend")" ]] || { echo 'Private backend port unexpectedly published' >&2; exit 1; }
}

start_backend ''
docker exec --user www-data "$backend" php /var/www/html/bin/dav init >/dev/null
printf 'disposable-test-password-12345\n' | docker exec --interactive --user www-data "$backend" \
  php /var/www/html/bin/dav user:add alice --password-stdin >/dev/null

# Nginx is the ONLY host-published test component, bound to loopback.
docker run --detach --name "$proxy" --network "$network" \
  --publish "127.0.0.1:${port}:8443" \
  --user "$(id -u):$(id -g)" \
  --read-only --tmpfs /tmp:rw,nosuid,size=16m \
  --tmpfs /var/cache/nginx:rw,nosuid,size=16m \
  --tmpfs /var/run:rw,nosuid,size=8m \
  --security-opt no-new-privileges:true \
  --cap-drop ALL \
  --mount "type=bind,src=${root}/tests/nginx-tls-smoke.conf,dst=/etc/nginx/nginx.conf,readonly" \
  --mount "type=bind,src=${workdir},dst=/etc/nginx/tls,readonly" \
  nginx:stable-alpine >/dev/null

published="$(docker port "$proxy" 8443/tcp)"
[[ "$published" == "127.0.0.1:${port}" ]] || { echo "TLS listener not loopback-only: $published" >&2; exit 1; }
proxy_ip="$(docker inspect --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$proxy")"
if [[ -z "$proxy_ip" ]]; then
  echo 'Gateway did not join the Docker network; inspecting startup failure' >&2
  docker inspect --format 'Nginx status={{.State.Status}} exit={{.State.ExitCode}} error={{.State.Error}}' "$proxy" >&2 || true
  docker logs --tail 60 "$proxy" >&2 || true
  exit 1
fi

# Restart the backend with ONLY the actual proxy IP authorized to assert TLS.
docker rm -f "$backend" >/dev/null
start_backend "$proxy_ip"
docker exec "$proxy" nginx -s reload >/dev/null 2>&1

curl_tls() {
  curl --silent --show-error --noproxy '*' --connect-timeout 3 --max-time 15 \
    --cacert "$workdir/tls.crt" --resolve "dav.test:${port}:127.0.0.1" "$@"
}

code=''
for attempt in $(seq 1 40); do
  code="$(curl_tls --output /dev/null --write-out '%{http_code}' "$url/healthz" || true)"
  [[ "$code" == 200 ]] && break
  sleep 0.35
done
[[ "$code" == 200 ]] || { echo "TLS gateway health failed: $code" >&2; docker logs --tail 30 "$proxy" >&2 || true; exit 1; }

# TLS must be genuinely authenticated; without the ephemeral CA curl must reject it.
if curl --silent --show-error --noproxy '*' --connect-timeout 3 --max-time 8 \
  --resolve "dav.test:${port}:127.0.0.1" "$url/healthz" >/dev/null 2>&1; then
  echo 'Untrusted certificate was unexpectedly accepted' >&2
  exit 1
fi

curl_tls --dump-header "$workdir/headers" --output /dev/null "$url/healthz"
grep -iq '^Strict-Transport-Security: max-age=3600' "$workdir/headers" || {
  echo 'HSTS missing from TLS response' >&2; exit 1;
}

# The proxy must overwrite an attacker-controlled X-Forwarded-Proto header.
# Both values still permit access because TLS really terminated at nginx.
for forged in https http; do
  code="$(curl_tls --output /dev/null --write-out '%{http_code}' \
    --user 'alice:disposable-test-password-12345' \
    --request PROPFIND -H 'Depth: 0' -H "X-Forwarded-Proto: $forged" \
    "$url/calendars/alice/default/")"
  [[ "$code" == 207 ]] || { echo "TLS DAV PROPFIND failed for forged $forged: $code" >&2; exit 1; }
done

# An unrelated container on the same bridge must not impersonate the proxy.
# Its forwarded TLS assertion must be ignored (403 before authentication).
spoof_output="$(docker run --rm --network "$network" --entrypoint sh nginx:stable-alpine -c \
  'wget -S -O /dev/null --header="X-Forwarded-Proto: https" http://goreecloud-dav-tls-backend/calendars/alice/default/ 2>&1 || true')"
printf '%s' "$spoof_output" | grep -Eq 'HTTP/[0-9.]+ 403' || {
  echo 'Untrusted Docker peer spoofed the TLS proxy or the check could not run' >&2
  printf '%s\n' "$spoof_output" >&2
  exit 1
}

# Requests from one IP must be throttled by the gateway (not by PHP).
# Use 30 immediate requests against a 3r/s, burst=10 test fixture.
saw_429=0
for attempt in $(seq 1 30); do
  code="$(curl_tls --output /dev/null --write-out '%{http_code}' "$url/healthz")"
  if [[ "$code" == 429 ]]; then saw_429=1; break; fi
done
[[ "$saw_429" == 1 ]] || { echo 'Expected rate limit 429 never observed' >&2; exit 1; }

echo 'Disposable authenticated HTTPS, TLS certificate/HSTS, proxy allowlist, header override, bridge spoof denial, loopback binding and 429 throttle passed.'
