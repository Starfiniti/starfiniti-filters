#!/usr/bin/env bash
set -Eeuo pipefail

fail() {
  printf 'starfiniti-runtime: %s\n' "$*" >&2
  exit 1
}

[[ ${1:-} == "--apply" ]] || fail "refusing to mutate the host without --apply"
[[ $EUID -eq 0 ]] || fail "run as root"
[[ -r /etc/os-release ]] || fail "/etc/os-release is missing"

# shellcheck source=/dev/null
source /etc/os-release
[[ ${ID:-} == "ubuntu" && ${VERSION_ID:-} == "24.04" ]] ||
  fail "this lock is only approved for Ubuntu 24.04"
[[ $(dpkg --print-architecture) == "amd64" ]] || fail "amd64 is required"

docker_ce_version='5:29.7.2-1~ubuntu.24.04~noble'
containerd_version='2.3.3-1~ubuntu.24.04~noble'
buildx_version='0.36.1-1~ubuntu.24.04~noble'
compose_version='5.4.0-1~ubuntu.24.04~noble'

install -d -m 0755 /etc/apt/keyrings
curl --fail --silent --show-error --location \
  https://download.docker.com/linux/ubuntu/gpg \
  --output /etc/apt/keyrings/docker.asc
chmod 0644 /etc/apt/keyrings/docker.asc

printf '%s\n' \
  'deb [arch=amd64 signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu noble stable' \
  >/etc/apt/sources.list.d/docker.list

apt-get update
env DEBIAN_FRONTEND=noninteractive apt-get install -y \
  "docker-ce=${docker_ce_version}" \
  "docker-ce-cli=${docker_ce_version}" \
  "containerd.io=${containerd_version}" \
  "docker-buildx-plugin=${buildx_version}" \
  "docker-compose-plugin=${compose_version}"

apt-mark hold docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
systemctl enable --now docker

if getent passwd 10001 >/dev/null; then
  [[ $(getent passwd 10001 | cut -d: -f1) == "starfiniti-runtime" ]] ||
    fail "UID 10001 is already assigned"
else
  useradd --system --uid 10001 --user-group --home-dir /nonexistent \
    --shell /usr/sbin/nologin starfiniti-runtime
fi

install -d -m 0750 -o root -g starfiniti-runtime /etc/starfiniti-search
install -d -m 0750 -o starfiniti-runtime -g starfiniti-runtime \
  /srv/starfiniti-search/typesense/data \
  /srv/starfiniti-search/typesense/logs \
  /srv/starfiniti-search/typesense/snapshots

docker version
docker compose version
printf 'starfiniti-runtime: pinned runtime installed; no workload was started\n'
