# Private Typesense 30.2 certification environment

The runtime is prepared for VM `960` with 4 vCPU and 8 GiB RAM. Typesense is
limited to 2 CPU and 3 GiB RAM and binds only to VM loopback. No Typesense port,
bootstrap key, or admin API is exposed on `vmbr10` or the Internet.

`infra/runtime-lock.json` records both the multi-platform manifest digest and the
Linux/amd64 child digest. The Compose file uses the manifest digest, so a tag move
cannot silently change the certified image.

`validate-certifier.sh` is a harness test, not container-image evidence. It
refuses to extract or execute its standalone Typesense archive unless the bytes
match `validation_binary_sha256`, and records that binary digest and artifact
kind in its temporary evidence.

## Deployment gate

Do not run the workload until the encrypted Storage Box repository has produced a
verified backup and a restore rehearsal. After that gate:

1. Run `bootstrap-docker.sh --apply` on Ubuntu 24.04 VM `960`.
2. Generate a high-entropy Typesense bootstrap key outside Git.
3. Copy `typesense-server.ini.example` to
   `/etc/starfiniti-search/typesense-server.ini`, replace the marker, and set
   owner `root:starfiniti-runtime`, mode `0640`.
4. Copy `compose.yaml` to `/srv/starfiniti-search/compose.yaml`.
5. Run `docker compose pull`, inspect the resolved digest, then start Typesense.
6. Wait for `curl --fail http://127.0.0.1:8108/health`.
7. Put the bootstrap key alone in a mode-`0600` file and run
   `certify_typesense.py`. The script creates four independent temporary keys,
   exercises partial imports, mandatory filtering, alias activation/rollback,
   and the v30 synonym/curation APIs, then deletes all temporary resources.

Example:

```bash
python3 certify_typesense.py \
  --admin-key-file /etc/starfiniti-search/typesense-admin-key \
  --output /var/lib/starfiniti-search/evidence/typesense-service.json
```

The generated JSON is service-level evidence only. Provider activation remains
blocked until the installed WordPress artifact passes the complete adapter,
visibility, relevance, outage, snapshot/restore, and strict latency suites.
