#!/usr/bin/env bash
set -Eeuo pipefail

umask 077

config_file="${STARFINITI_BACKUP_CONFIG:-/etc/starfiniti-backup/pve-borg.env}"
lock_file="${STARFINITI_BACKUP_LOCK:-/run/lock/starfiniti-pve-borg.lock}"

fail() {
  printf 'starfiniti-backup: %s\n' "$*" >&2
  exit 1
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || fail "required command is missing: $1"
}

[[ $EUID -eq 0 ]] || fail "run as root"
[[ -r "$config_file" ]] || fail "configuration is not readable: $config_file"

# shellcheck source=/dev/null
source "$config_file"

: "${BORG_REPO:?BORG_REPO is required}"
: "${BORG_REMOTE_PATH:=borg-1.4}"
: "${BORG_RSH:?BORG_RSH is required}"
: "${BORG_PASSCOMMAND:?BORG_PASSCOMMAND is required}"
: "${BORG_COMPRESSION:=zstd,3}"
: "${PVE_GUEST_IDS:=}"
: "${PVE_INCLUDE_TEMPLATES:=0}"
: "${PVE_THIN_POOL_LV:=vg0/data}"
: "${PVE_MAX_DATA_PERCENT:=80}"
: "${PVE_MAX_METADATA_PERCENT:=70}"

export BORG_REPO BORG_RSH BORG_PASSCOMMAND
export BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK=no
export BORG_RELOCATED_REPO_ACCESS_IS_OK=no

for command_name in awk borg flock lvs pct qm sort vzdump; do
  require_command "$command_name"
done

install -d -m 0755 "$(dirname "$lock_file")"
exec 9>"$lock_file"
flock -n 9 || fail "another backup run holds $lock_file"

read -r data_percent metadata_percent < <(
  lvs --noheadings --nosuffix -o data_percent,metadata_percent "$PVE_THIN_POOL_LV" |
    awk '{ print $1, $2 }'
)

[[ -n "$data_percent" && -n "$metadata_percent" ]] ||
  fail "could not read thin-pool utilization for $PVE_THIN_POOL_LV"

awk -v actual="$data_percent" -v maximum="$PVE_MAX_DATA_PERCENT" \
  'BEGIN { exit !(actual < maximum) }' ||
  fail "thin-pool data usage ${data_percent}% is at or above ${PVE_MAX_DATA_PERCENT}%"

awk -v actual="$metadata_percent" -v maximum="$PVE_MAX_METADATA_PERCENT" \
  'BEGIN { exit !(actual < maximum) }' ||
  fail "thin-pool metadata usage ${metadata_percent}% is at or above ${PVE_MAX_METADATA_PERCENT}%"

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"

declare -a guest_ids=()
if [[ -n "$PVE_GUEST_IDS" ]]; then
  read -r -a guest_ids <<<"$PVE_GUEST_IDS"
else
  while read -r guest_id; do
    [[ -n "$guest_id" ]] && guest_ids+=("$guest_id")
  done < <(
    {
      qm list | awk 'NR > 1 { print $1 }'
      pct list | awk 'NR > 1 { print $1 }'
    } | sort -n -u
  )
fi

[[ ${#guest_ids[@]} -gt 0 ]] || fail "no guests selected"

backup_guest() {
  local guest_id="$1"
  local guest_type archive_name stream_name

  [[ "$guest_id" =~ ^[1-9][0-9]*$ ]] || fail "invalid guest ID: $guest_id"

  if qm status "$guest_id" >/dev/null 2>&1; then
    if [[ "$PVE_INCLUDE_TEMPLATES" != "1" ]] &&
      qm config "$guest_id" | grep -q '^template: 1$'; then
      printf 'starfiniti-backup: skipping QEMU template %s\n' "$guest_id"
      return 0
    fi
    guest_type="qemu"
    stream_name="vzdump-qemu-${guest_id}.vma"
  elif pct status "$guest_id" >/dev/null 2>&1; then
    guest_type="lxc"
    stream_name="vzdump-lxc-${guest_id}.tar"
  else
    fail "guest $guest_id does not exist"
  fi

  archive_name="pve-${guest_type}-${guest_id}-${timestamp}"
  printf 'starfiniti-backup: creating %s\n' "$archive_name"

  ionice -c2 -n7 nice -n 10 \
    borg create \
      --remote-path "$BORG_REMOTE_PATH" \
      --compression "$BORG_COMPRESSION" \
      --files-cache disabled \
      --show-rc \
      --stats \
      --content-from-command \
      --stdin-name "$stream_name" \
      "${BORG_REPO}::${archive_name}" \
      -- vzdump "$guest_id" --stdout 1 --mode snapshot --compress 0 --quiet 1
}

backup_host_configuration() {
  local archive_name="pve-host-config-${timestamp}"
  local -a paths=(
    /etc/pve
    /etc/network/interfaces
    /etc/hosts
    /etc/hostname
    /etc/vzdump.conf
    /etc/lvm
    /var/lib/pve-cluster/config.db
  )
  local -a existing_paths=()
  local path

  for path in "${paths[@]}"; do
    [[ -e "$path" ]] && existing_paths+=("$path")
  done

  [[ ${#existing_paths[@]} -gt 0 ]] || fail "no Proxmox host configuration paths exist"

  printf 'starfiniti-backup: creating %s\n' "$archive_name"
  ionice -c2 -n7 nice -n 10 \
    borg create \
      --remote-path "$BORG_REMOTE_PATH" \
      --compression "$BORG_COMPRESSION" \
      --show-rc \
      --stats \
      "${BORG_REPO}::${archive_name}" \
      "${existing_paths[@]}"
}

backup_host_configuration
for guest_id in "${guest_ids[@]}"; do
  backup_guest "$guest_id"
done

borg list --remote-path "$BORG_REMOTE_PATH" --last "$((${#guest_ids[@]} + 1))" "$BORG_REPO"
printf 'starfiniti-backup: completed at %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
