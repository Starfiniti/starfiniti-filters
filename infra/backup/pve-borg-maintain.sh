#!/usr/bin/env bash
set -Eeuo pipefail

umask 077

config_file="${STARFINITI_BACKUP_CONFIG:-/etc/starfiniti-backup/pve-borg-maintenance.env}"

fail() {
  printf 'starfiniti-backup-maintenance: %s\n' "$*" >&2
  exit 1
}

[[ $EUID -eq 0 ]] || fail "run as root on the designated maintenance client"
[[ -r "$config_file" ]] || fail "configuration is not readable: $config_file"

# shellcheck source=/dev/null
source "$config_file"

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

command -v borg >/dev/null 2>&1 || fail "borg is missing"

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
