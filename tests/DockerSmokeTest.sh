#!/usr/bin/env bash
# Non-production, loopback-only container smoke test. Requires Docker and curl.
# Never run against a real service or mount an existing data volume.
set -euo pipefail

image="goreecloud-dav:ci-smoke"
identifier="${GITHUB_RUN_ID:-local}-$$"
container="goreecloud-dav-ci-${identifier}"
volume="goreecloud-dav-ci-${identifier}"
port="${DAV_SMOKE_PORT:-18787}"
base="http://127.0.0.1:${port}"

cleanup() {
  docker rm -f "$container" >/dev/null 2>&1 || true
  docker volume rm -f "$volume" >/dev/null 2>&1 || true
}
trap cleanup EXIT

# Build from the checked-in Dockerfile, including actual Composer dependencies.
docker build --tag "$image" .
docker volume create "$volume" >/dev/null

docker run --detach --name "$container" \
  --read-only \
  --tmpfs /tmp:rw,noexec,nosuid,size=64m \
  --tmpfs /run/apache2:rw,nosuid,size=8m \
  --tmpfs /var/lock/apache2:rw,nosuid,size=8m \
  --security-opt no-new-privileges:true \
  --cap-drop ALL --cap-add NET_BIND_SERVICE --cap-add SETUID --cap-add SETGID \
  --publish "127.0.0.1:${port}:80" \
  --env DAV_REQUIRE_HTTPS=1 \
  --mount "type=volume,src=${volume},dst=/var/lib/goreecloud-dav" \
  "$image" >/dev/null

for attempt in $(seq 1 30); do
  code="$(curl --silent --output /dev/null --write-out '%{http_code}' --max-time 2 "$base/healthz" || true)"
  if [[ "$code" == 200 ]]; then break; fi
  sleep 0.3
done
if [[ "$code" != 200 ]]; then
  echo 'Container HTTP health probe failed to start' >&2
  docker logs --tail 50 "$container" >&2 || true
  exit 1
fi

# Administrative CLI must operate as the SAME private-data owner as Apache.
docker exec --user www-data "$container" php /var/www/html/bin/dav init
users="$(docker exec --user www-data "$container" php /var/www/html/bin/dav user:list --json)"
if [[ "$users" != '[]' ]]; then
  echo 'Fresh container must have no seeded DAV accounts' >&2
  exit 1
fi
printf 'disposable-test-password-12345\n' | docker exec --interactive --user www-data "$container" \
  php /var/www/html/bin/dav user:add alice --password-stdin

docker exec --user www-data "$container" php /var/www/html/bin/dav status --json | grep -q '"accounts":1'

# The container is deliberately not configured with a trusted proxy. A client
# claiming X-Forwarded-Proto:https must NOT bypass plaintext HTTP rejection.
for request in direct spoofed; do
  headers=()
  if [[ "$request" == spoofed ]]; then headers=(-H 'X-Forwarded-Proto: https'); fi
  code="$(curl --silent --output /dev/null --write-out '%{http_code}' --max-time 10 \
    --user 'alice:disposable-test-password-12345' --request PROPFIND \
    -H 'Depth: 0' "${headers[@]}" "$base/")"
  if [[ "$code" != 403 ]]; then
    echo "Plaintext DAV must return 403 (test=${request}, status=${code})" >&2
    exit 1
  fi
done

# A second throwaway container simulates a TLS-terminating direct proxy on
# the Docker bridge. Only the observed bridge gateway is allowlisted. This is
# NOT a recommendation to trust the Docker gateway in production.
gateway="$(docker inspect --format '{{range .NetworkSettings.Networks}}{{.Gateway}}{{end}}' "$container")"
if [[ -z "$gateway" ]]; then
  echo 'Cannot determine disposable container network gateway' >&2
  exit 1
fi
docker rm -f "$container" >/dev/null

docker run --detach --name "$container" \
  --read-only \
  --tmpfs /tmp:rw,noexec,nosuid,size=64m \
  --tmpfs /run/apache2:rw,nosuid,size=8m \
  --tmpfs /var/lock/apache2:rw,nosuid,size=8m \
  --security-opt no-new-privileges:true \
  --cap-drop ALL --cap-add NET_BIND_SERVICE --cap-add SETUID --cap-add SETGID \
  --publish "127.0.0.1:${port}:80" \
  --env DAV_REQUIRE_HTTPS=1 \
  --env "DAV_TRUSTED_PROXIES=${gateway}" \
  --mount "type=volume,src=${volume},dst=/var/lib/goreecloud-dav" \
  "$image" >/dev/null

for attempt in $(seq 1 30); do
  code="$(curl --silent --output /dev/null --write-out '%{http_code}' --max-time 2 "$base/healthz" || true)"
  if [[ "$code" == 200 ]]; then break; fi
  sleep 0.3
done
[[ "$code" == 200 ]] || { echo 'Trusted-proxy test container failed health check' >&2; exit 1; }

# Without a forwarded HTTPS header the request must still fail.
code="$(curl --silent --output /dev/null --write-out '%{http_code}' --max-time 10 \
  --user 'alice:disposable-test-password-12345' --request PROPFIND -H 'Depth: 0' "$base/")"
[[ "$code" == 403 ]] || { echo "No-TLS request unexpectedly accepted: $code" >&2; exit 1; }

# Only a directly connected, explicitly allowlisted proxy may convey HTTPS.
code="$(curl --silent --output /dev/null --write-out '%{http_code}' --max-time 10 \
  --user 'alice:disposable-test-password-12345' --request PROPFIND -H 'Depth: 0' \
  -H 'X-Forwarded-Proto: https' "$base/calendars/alice/default/")"
[[ "$code" == 207 ]] || { echo "Trusted-proxy CalDAV PROPFIND failed: $code" >&2; exit 1; }

# Exercise the operator backup against the actual PHP PDO_SQLITE runtime in
# the disposable container; the snapshot stays in the ephemeral /tmp tmpfs.
docker exec --user www-data "$container" php /var/www/html/bin/dav backup:create /tmp/test-dav-backup --json \
  | grep -q '"users":1'
docker exec --user www-data "$container" php /var/www/html/bin/dav backup:verify /tmp/test-dav-backup --json \
  | grep -q '"files":2'
docker exec --user www-data "$container" php -r '
  $db = new PDO("sqlite:/tmp/test-dav-backup/dav.sqlite");
  if ($db->query("PRAGMA integrity_check")->fetchColumn() !== "ok") exit(1);
  if ((int) $db->query("SELECT COUNT(*) FROM goreecloud_accounts")->fetchColumn() !== 1) exit(1);
'

echo 'Disposable Docker/Apache startup, PHP provisioning, trusted-proxy TLS and SQLite backup passed.'
