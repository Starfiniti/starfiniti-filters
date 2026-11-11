#!/usr/bin/env bash
set -Eeuo pipefail

work_dir="${1:-/tmp/starfiniti-typesense-certifier-validation}"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
certifier="${2:-$script_dir/certify_typesense.py}"
runtime_lock="${3:-$script_dir/../runtime-lock.json}"
server_pid=""

[[ "$work_dir" == /tmp/starfiniti-typesense-certifier-validation ]] || {
  printf 'refusing unexpected work path: %s\n' "$work_dir" >&2
  exit 1
}
[[ -r "$certifier" ]] || {
  printf 'certifier is not readable: %s\n' "$certifier" >&2
  exit 1
}
[[ -r "$runtime_lock" ]] || {
  printf 'runtime lock is not readable: %s\n' "$runtime_lock" >&2
  exit 1
}

expected_binary_sha256="$(jq -er '.images.typesense.validation_binary_sha256' "$runtime_lock")"
[[ "$expected_binary_sha256" =~ ^[a-f0-9]{64}$ ]] || {
  printf 'runtime lock contains an invalid Typesense validation digest\n' >&2
  exit 1
}

cleanup() {
  if [[ -n "$server_pid" ]] && kill -0 "$server_pid" 2>/dev/null; then
    kill "$server_pid"
    wait "$server_pid" || true
  fi
  rm -rf -- /tmp/starfiniti-typesense-certifier-validation
}
trap cleanup EXIT
cleanup
install -d -m 0700 "$work_dir/data" "$work_dir/logs"

curl --fail --silent --show-error --location \
  https://dl.typesense.org/releases/30.2/typesense-server-30.2-linux-amd64.tar.gz \
  --output "$work_dir/typesense.tar.gz"
printf '%s  %s\n' "$expected_binary_sha256" "$work_dir/typesense.tar.gz" |
  sha256sum --check --status || {
  printf 'Typesense validation archive does not match the runtime lock\n' >&2
  exit 1
}

python3 - "$work_dir/typesense.tar.gz" "$work_dir" <<'PY'
import pathlib
import sys
import tarfile

archive = pathlib.Path(sys.argv[1])
destination = pathlib.Path(sys.argv[2]).resolve()
with tarfile.open(archive) as source:
    for member in source.getmembers():
        target = (destination / member.name).resolve()
        if destination not in target.parents and target != destination:
            raise SystemExit(f"unsafe tar member: {member.name}")
    source.extractall(destination, filter="data")
PY

openssl rand -hex 32 >"$work_dir/admin-key"
chmod 0600 "$work_dir/admin-key"

{
  printf '[server]\n'
  printf 'api-key = %s\n' "$(cat "$work_dir/admin-key")"
  printf 'data-dir = %s\n' "$work_dir/data"
  printf 'log-dir = %s\n' "$work_dir/logs"
  printf 'api-port = 18108\n'
  printf 'listen-address = 127.0.0.1\n'
  printf 'enable-cors = false\n'
  printf 'enable-search-logging = false\n'
  printf 'snapshot-interval-seconds = 3600\n'
} >"$work_dir/typesense.ini"
chmod 0600 "$work_dir/typesense.ini"

"$work_dir/typesense-server" --config="$work_dir/typesense.ini" \
  >"$work_dir/server.stdout" 2>"$work_dir/server.stderr" &
server_pid="$!"

for _ in $(seq 1 30); do
  if curl --fail --silent http://127.0.0.1:18108/health >/dev/null; then
    break
  fi
  sleep 1
done
curl --fail --silent http://127.0.0.1:18108/health >/dev/null

python3 "$certifier" \
  --url http://127.0.0.1:18108 \
  --admin-key-file "$work_dir/admin-key" \
  --artifact-kind standalone-binary \
  --artifact-digest "sha256:$expected_binary_sha256" \
  --output "$work_dir/evidence.json"
jq -e '.status == "passed" and (.cleanup_errors | length == 0)' "$work_dir/evidence.json" >/dev/null

for endpoint in collections aliases synonym_sets curation_sets keys; do
  response="$work_dir/${endpoint}.json"
  curl --fail --silent \
    -H "X-TYPESENSE-API-KEY: $(cat "$work_dir/admin-key")" \
    "http://127.0.0.1:18108/${endpoint}" >"$response"
  if grep -q 'sfs_cert_' "$response"; then
    printf 'temporary certification resource remains in %s\n' "$endpoint" >&2
    exit 1
  fi
done

printf 'typesense-certifier-validation-ok\n'
