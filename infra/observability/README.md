# Private observability stack

This stack is sized for LXC `105` with 2 GiB RAM. Hard container limits total
1.504 GiB: Prometheus 512 MiB, Loki 384 MiB, Grafana 320 MiB, Alloy 192 MiB,
and node-exporter 96 MiB. This leaves approximately 500 MiB for Ubuntu, Docker,
filesystem cache, and short-lived overhead. Retention is deliberately seven days.

Grafana and Prometheus bind only to container loopback. Reach Grafana through an
SSH tunnel, not a public reverse proxy. Loki binds to `10.10.10.61:3100` solely
for the certification VM log agent. Before starting the stack, add these exact
guest firewall rules and no broader rules:

- VM `960`: allow TCP/9100 from `10.10.10.61` only.
- LXC `105`: allow TCP/3100 from `10.10.10.60` only.

Generate `/etc/starfiniti-observability/grafana-admin-password` outside Git with
at least 32 random characters, owner `root:root`, mode `0600`. Anonymous Grafana
access and telemetry are disabled. Loki has no application authentication, which
is acceptable only because it is isolated by the Docker network and the exact
host/UFW rule above.

Do not deploy until the Storage Box backup and restore proof passes. After that:

1. Install the pinned Docker runtime with the certification bootstrap script.
2. Copy this directory to `/srv/starfiniti-observability` on LXC `105`.
3. Create the Grafana secret and run `docker compose config`.
4. Pull images and compare resolved digests with `infra/runtime-lock.json`.
5. Start the ops stack, then deploy `cert-agent.compose.yaml` and
   `cert-alloy.alloy` to VM `960`.
6. Verify both Prometheus targets, inject a harmless test log, and confirm it in
   Loki through Grafana.
7. Watch LXC memory and OOM counters for 24 hours before adding any component.

The stack contains no paging destination yet. Alerts are visible in Prometheus;
external notification delivery requires a separately approved destination.
