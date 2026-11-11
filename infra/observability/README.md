# Private observability stack

This stack is sized for LXC `105` with 2 GiB RAM. Hard container limits total
1.504 GiB: Prometheus 512 MiB, Loki 384 MiB, Grafana 320 MiB, Alloy 192 MiB,
and node-exporter 96 MiB. This leaves approximately 500 MiB for Ubuntu, Docker,
filesystem cache, and short-lived overhead. Retention is deliberately seven days.

Grafana and Prometheus bind only to container loopback. Reach Grafana through an
SSH tunnel, not a public reverse proxy. Loki binds to `10.10.10.61:3100` solely
for the certification VM log agent. Prometheus joins a separate egress bridge so
it can scrape the VM; no other observability service receives general egress.

Docker-published ports bypass normal UFW input filtering. Before starting either
stack, install `starfiniti-docker-ingress-firewall` and its systemd unit, then
create the root-owned mode-`0600` configuration from `firewall.env.example`:

- VM `960`: local `10.10.10.60`, remote `10.10.10.61`, TCP/9100.
- LXC `105`: local `10.10.10.61`, remote `10.10.10.60`, TCP/3100.

Set the private interface name separately on each guest. Enable and start the
firewall unit before `docker compose up`; it installs both the exact UFW rule and
an allow-then-drop policy at the head of Docker's `DOCKER-USER` forwarding chain.
Reload the unit after any manual Docker/UFW ruleset reload. Verify from the
allowed peer and from a second denied `vmbr10` peer.

```bash
install -m 0755 starfiniti-docker-ingress-firewall /usr/local/sbin/
install -m 0644 starfiniti-docker-ingress-firewall.service /etc/systemd/system/
install -d -m 0700 /etc/starfiniti-observability
install -m 0600 firewall.env.example /etc/starfiniti-observability/firewall.env
# Edit only the interface/address/port values documented above.
systemctl daemon-reload
systemctl enable --now starfiniti-docker-ingress-firewall.service
```

Generate `/etc/starfiniti-observability/grafana-admin-password` outside Git with
at least 32 random characters, owner `root:root`, mode `0600`. Anonymous Grafana
access and telemetry are disabled. Loki has no application authentication, which
is acceptable only while the exact persistent `DOCKER-USER` policy above is
active and independently verified.

Do not deploy until the Storage Box backup and restore proof passes. After that:

1. Install the pinned Docker runtime with the certification bootstrap script.
2. Copy this directory to `/srv/starfiniti-observability` on LXC `105`.
3. Install and verify the firewall unit on both guests.
4. Create the Grafana secret and run `docker compose config`.
5. Pull images and compare resolved digests with `infra/runtime-lock.json`.
6. Start the ops stack, then deploy `cert-agent.compose.yaml` and
   `cert-alloy.alloy` to VM `960`.
7. Verify both Prometheus targets, inject a harmless test log, and confirm it in
   Loki through Grafana.
8. Watch LXC memory and OOM counters for 24 hours before adding any component.

The stack contains no paging destination yet. Alerts are visible in Prometheus;
external notification delivery requires a separately approved destination.
