#!/usr/bin/env bash
set -Eeuo pipefail

work_dir="${1:-/tmp/starfiniti-observability-validation}"
config_dir="${2:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)}"

[[ "$work_dir" == /tmp/starfiniti-observability-validation ]] || {
  printf 'refusing unexpected work path: %s\n' "$work_dir" >&2
  exit 1
}

cleanup() {
  rm -rf -- /tmp/starfiniti-observability-validation
}
trap cleanup EXIT
cleanup
install -d -m 0700 "$work_dir"

curl --fail --silent --show-error --location \
  https://github.com/prometheus/prometheus/releases/download/v3.13.2/prometheus-3.13.2.linux-amd64.tar.gz \
  --output "$work_dir/prometheus.tar.gz"
printf '%s  %s\n' \
  '0e8c4d46101bd025ea8265e377d2caabc57f488fc1be1c367f37db69ea41be6f' \
  "$work_dir/prometheus.tar.gz" | sha256sum --check --status
tar -xzf "$work_dir/prometheus.tar.gz" -C "$work_dir"
sed "s#/etc/prometheus/alerts.yml#${config_dir}/alerts.yml#" \
  "$config_dir/prometheus.yml" >"$work_dir/prometheus-validation.yml"
"$work_dir/prometheus-3.13.2.linux-amd64/promtool" check config "$work_dir/prometheus-validation.yml"
"$work_dir/prometheus-3.13.2.linux-amd64/promtool" check rules "$config_dir/alerts.yml"

curl --fail --silent --show-error --location \
  https://github.com/grafana/loki/releases/download/v3.6.15/loki-linux-amd64.zip \
  --output "$work_dir/loki.zip"
printf '%s  %s\n' \
  '91de08b33c450b24862a2628050a3ef904ab840d7f9774d3deced414a4b9bf15' \
  "$work_dir/loki.zip" | sha256sum --check --status
python3 - "$work_dir/loki.zip" "$work_dir" <<'PY'
import pathlib
import sys
import zipfile

archive = pathlib.Path(sys.argv[1])
destination = pathlib.Path(sys.argv[2]).resolve()
with zipfile.ZipFile(archive) as source:
    for member in source.infolist():
        target = (destination / member.filename).resolve()
        if destination not in target.parents:
            raise SystemExit(f"unsafe zip member: {member.filename}")
    source.extractall(destination)
PY
chmod 0700 "$work_dir/loki-linux-amd64"
"$work_dir/loki-linux-amd64" -verify-config=true -config.file="$config_dir/loki.yml"

curl --fail --silent --show-error --location \
  https://github.com/grafana/alloy/releases/download/v1.18.1/alloy-linux-amd64.zip \
  --output "$work_dir/alloy.zip"
printf '%s  %s\n' \
  'fac853cbc3983a50a2368f9a685b31f74392ae86dd6155461b11a911c07b483c' \
  "$work_dir/alloy.zip" | sha256sum --check --status
python3 - "$work_dir/alloy.zip" "$work_dir" <<'PY'
import pathlib
import sys
import zipfile

archive = pathlib.Path(sys.argv[1])
destination = pathlib.Path(sys.argv[2]).resolve()
with zipfile.ZipFile(archive) as source:
    for member in source.infolist():
        target = (destination / member.filename).resolve()
        if destination not in target.parents:
            raise SystemExit(f"unsafe zip member: {member.filename}")
    source.extractall(destination)
PY
chmod 0700 "$work_dir/alloy-linux-amd64"
"$work_dir/alloy-linux-amd64" validate "$config_dir/ops-alloy.alloy"
"$work_dir/alloy-linux-amd64" validate "$config_dir/cert-alloy.alloy"

printf 'observability-config-validation-ok\n'
