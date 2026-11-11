#!/usr/bin/env bash
set -Eeuo pipefail

umask 077

config_file="${STARFINITI_BACKUP_CONFIG:-/etc/starfiniti-backup/pve-borg-maintenance.env}"
lock_file="${STARFINITI_BACKUP_MAINTENANCE_LOCK:-/run/lock/starfiniti-pve-borg-maintenance.lock}"

fail() {
  printf 'starfiniti-backup-maintenance: %s\n' "$*" >&2
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
for command_name in borg flock stat; do
  command -v "$command_name" >/dev/null 2>&1 || fail "$command_name is missing"
done
load_config

: "${BORG_REPO:?BORG_REPO is required}"
: "${BORG_REMOTE_PATH:=borg-1.4}"
: "${BORG_RSH:?BORG_RSH is required}"
: "${BORG_PASSCOMMAND:?BORG_PASSCOMMAND is required}"
: "${PVE_GUEST_IDS:?PVE_GUEST_IDS must explicitly list every retained guest}"
: "${KEEP_DAILY:=7}"
: "${KEEP_WEEKLY:=4}"
: "${KEEP_MONTHLY:=6}"
: "${KEEP_YEARLY:=2}"

export BORG_REPO BORG_RSH BORG_PASSCOMMAND
export BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK=no
export BORG_RELOCATED_REPO_ACCESS_IS_OK=no

install -d -m 0755 "$(dirname "$lock_file")"
exec 9>"$lock_file"
flock -n 9 || fail "another maintenance run holds $lock_file"

prune_series() {
  local archive_glob="$1"
  borg prune \
    --remote-path "$BORG_REMOTE_PATH" \
    --list \
    --show-rc \
    --glob-archives "$archive_glob" \
    --keep-daily "$KEEP_DAILY" \
    --keep-weekly "$KEEP_WEEKLY" \
    --keep-monthly "$KEEP_MONTHLY" \
    --keep-yearly "$KEEP_YEARLY" \
    "$BORG_REPO"
}

prune_series 'pve-host-config-*'
for guest_id in $PVE_GUEST_IDS; do
  [[ "$guest_id" =~ ^[1-9][0-9]*$ ]] || fail "invalid guest ID: $guest_id"
  prune_series "pve-qemu-${guest_id}-*"
  prune_series "pve-lxc-${guest_id}-*"
done

borg compact --remote-path "$BORG_REMOTE_PATH" --show-rc "$BORG_REPO"
borg check --remote-path "$BORG_REMOTE_PATH" --repository-only --show-rc "$BORG_REPO"
