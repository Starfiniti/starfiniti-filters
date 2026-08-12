#!/usr/bin/env bash
set -Eeuo pipefail

umask 077

config_file="${STARFINITI_BACKUP_CONFIG:-/etc/starfiniti-backup/pve-borg-maintenance.env}"

fail() {
  printf 'starfiniti-backup-verify: %s\n' "$*" >&2
  exit 1
}

load_config() {
  local config_fd owner mode
  [[ -f "$config_file" && ! -L "$config_file" ]] || fail "configuration must be a regular non-symlink file: $config_file"
  exec {config_fd}<"$config_file" || fail "configuration is not readable: $config_file"
  owner="$(stat -Lc '%u' "/proc/self/fd/$config_fd")"
  mode="$(stat -Lc '%a' "/proc/self/fd/$config_fd")"
  [[ "$owner" == "0" ]] || fail "configuration must be owned by root: $config_file"
  (( (8#$mode & 0077) == 0 )) || fail "configuration must not grant group or other permissions: $config_file"
  # shellcheck source=/dev/null
  source "/proc/self/fd/$config_fd"
  exec {config_fd}<&-
}

[[ $EUID -eq 0 ]] || fail "run as root on the designated maintenance client"
for command_name in borg jq stat; do
  command -v "$command_name" >/dev/null 2>&1 || fail "$command_name is missing"
done
load_config

: "${BORG_REPO:?BORG_REPO is required}"
: "${BORG_REMOTE_PATH:=borg-1.4}"
: "${BORG_RSH:?BORG_RSH is required}"
: "${BORG_PASSCOMMAND:?BORG_PASSCOMMAND is required}"
: "${PVE_GUEST_IDS:?PVE_GUEST_IDS must explicitly list every verified guest}"

export BORG_REPO BORG_RSH BORG_PASSCOMMAND
export BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK=no
export BORG_RELOCATED_REPO_ACCESS_IS_OK=no

verify_latest() {
  local archive_glob="$1"
  local archive_name

  archive_name="$({
    borg list --remote-path "$BORG_REMOTE_PATH" --json --glob-archives "$archive_glob" "$BORG_REPO"
  } | jq -r '.archives | sort_by(.time) | last | .name // empty')"

  [[ -n "$archive_name" ]] || fail "no archive matches $archive_glob"
  printf 'starfiniti-backup-verify: extracting %s in dry-run mode\n' "$archive_name"
  borg extract \
    --remote-path "$BORG_REMOTE_PATH" \
    --dry-run \
    --show-rc \
    "${BORG_REPO}::${archive_name}"
}

borg check --remote-path "$BORG_REMOTE_PATH" --repository-only --show-rc "$BORG_REPO"
verify_latest 'pve-host-config-*'

for guest_id in $PVE_GUEST_IDS; do
  [[ "$guest_id" =~ ^[1-9][0-9]*$ ]] || fail "invalid guest ID: $guest_id"
  if borg list --remote-path "$BORG_REMOTE_PATH" --short --glob-archives "pve-qemu-${guest_id}-*" "$BORG_REPO" | grep -q .; then
    verify_latest "pve-qemu-${guest_id}-*"
  elif borg list --remote-path "$BORG_REMOTE_PATH" --short --glob-archives "pve-lxc-${guest_id}-*" "$BORG_REPO" | grep -q .; then
    verify_latest "pve-lxc-${guest_id}-*"
  else
    fail "no QEMU or LXC archive exists for guest $guest_id"
  fi
done

printf 'starfiniti-backup-verify: all latest archives passed at %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
