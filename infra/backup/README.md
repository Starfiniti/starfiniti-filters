# Encrypted Proxmox backup to Hetzner Storage Box

These assets prepare a customer-side encrypted Borg 1.4 backup of every selected
QEMU VM and LXC container on `s2`. `vzdump` streams an uncompressed, consistent
snapshot directly into Borg. Borg performs authenticated encryption,
content-defined deduplication, and compression, so the Proxmox host does not need
a large local staging filesystem.

Nothing in this directory contains a hostname, account, private key, repository
passphrase, or Borg recovery key. Do not add those values to Git.

## Storage Box setup gate

After purchasing the box:

1. Enable SSH/Borg access in Hetzner Console.
2. Create a dedicated sub-account restricted to the `starfiniti-s2` directory.
3. Add a dedicated Ed25519 public key. Keep the private key only on the backup
   client and store the recovery copy independently.
4. Capture and verify the Storage Box SSH host key out of band, then write it to
   `/etc/starfiniti-backup/storage-box-known-hosts`.
5. Install Borg 1.4 and `jq` from the Debian repositories on the Proxmox host.
6. Generate a high-entropy Borg passphrase outside the repository. Store it in
   `/etc/starfiniti-backup/borg-passphrase`, mode `0600`, and place an encrypted
   recovery copy plus `borg key export` output in a separate custody system.
7. Copy `storage-box.env.example` to `/etc/starfiniti-backup/pve-borg.env`, fill
   only the external values, and set owner `root:root`, mode `0600`.
8. Initialize with `borg init --encryption=repokey-blake2 --remote-path=borg-1.4
   "$BORG_REPO"`, export the recovery key, and run one guest backup manually.
9. Run `pve-borg-verify.sh`, restore that archive to a new stopped guest ID, and
   verify the restored application on an isolated network before enabling the
   timer.

The backup client must not hold the unrestricted maintenance credential. Use a
separate root-only configuration for `pve-borg-maintain.sh`. Borg append-only
mode should protect the daily writer; retention and compaction run only from the
maintenance role.

## Retention and capacity

The prepared maintenance defaults retain 7 daily, 4 weekly, 6 monthly, and 2
yearly archives per guest. Retention is evaluated independently for every VM and
container so one busy guest cannot evict another guest's history. Do not enable
Hetzner Storage Box snapshots until repository growth is measured because those
snapshots consume the same 5 TB quota.

Alert at 70 and 80 percent usage. Stop creating new snapshots and investigate at
90 percent. Never prune merely to silence an alert; first confirm that a newer
verified backup and an independent recovery key exist.

## Restore proof

`pve-borg-verify.sh` performs a repository check and a complete authenticated,
decrypted, decompressed dry-run extraction of the newest archive for each guest.
That is necessary but not sufficient for the 8-hour RTO gate. The final proof is
a streamed restore to an unused stopped VM/LXC ID, detachment from production
networks, boot on an isolated bridge, application verification, recorded elapsed
time, and owner-approved cleanup. Never restore over the original guest.
