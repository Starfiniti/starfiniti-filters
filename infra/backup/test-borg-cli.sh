#!/usr/bin/env bash
set -Eeuo pipefail

test_repo="${1:-/run/starfiniti-borg-cli-test}"
[[ "$test_repo" == /run/starfiniti-borg-cli-test ]] || {
  printf 'refusing unexpected test path: %s\n' "$test_repo" >&2
  exit 1
}

cleanup() {
  rm -rf -- /run/starfiniti-borg-cli-test
}
trap cleanup EXIT

cleanup
borg init --encryption=none "$test_repo"
borg create \
  --compression zstd,3 \
  --files-cache disabled \
  --show-rc \
  --content-from-command \
  --stdin-name payload.bin \
  "${test_repo}::pve-qemu-999-20260810T000000Z" \
  -- printf test-payload
borg create "${test_repo}::pve-host-config-20260810T000000Z" /etc/hostname
borg list --last 2 "$test_repo"
borg list --json --glob-archives 'pve-qemu-999-*' "$test_repo" |
  jq -e '.archives | length == 1' >/dev/null
borg list --short --glob-archives 'pve-qemu-999-*' "$test_repo" | grep -q .
borg extract --dry-run --show-rc "${test_repo}::pve-qemu-999-20260810T000000Z"
borg prune --dry-run --glob-archives 'pve-qemu-999-*' --keep-daily 7 "$test_repo"
borg check --repository-only --show-rc "$test_repo"
printf 'borg-cli-contract-ok\n'
