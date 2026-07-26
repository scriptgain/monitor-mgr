# MonitorMGR Agent

The host-side agent for [MonitorMGR](https://scriptgain.com/products/monitormanager).
It enrolls with a MonitorMGR master and reports system metrics on an interval.

This repository is the **source** for the agent binary we distribute. It is
private and the source is not shipped to customers. Customers receive a compiled
binary served by their own master.

The agent only ever dials **out** to the master over HTTPS. It opens no inbound
ports and works behind any NAT or firewall, so it can monitor hosts a central
poller could never reach.

## Install (customer-facing)

Customers do not clone this repository. They create a host in the MonitorMGR
panel and run the install command it shows:

```sh
monitor-agent enroll -master https://monitor.example.com -token <ENROLL_TOKEN>
monitor-agent run
```

## Metrics

Each report carries host identity plus current resource state:

| Group | Fields |
|---|---|
| Host | `hostname`, `os`, `arch`, `uptime_seconds`, `boot_time` |
| CPU | `cpu_pct`, `cpu_cores`, per-core `core` / `pct` |
| Memory | `mem_used`, `mem_total`, `swap_used`, `swap_total` |
| Disk | per-mount `device`, `mount`, `fstype`, `used`, `total`, `pct` |
| Network | `net_rx_bytes_sec`, `net_tx_bytes_sec` |

Network throughput is reported as a rate, so the master stores bytes per second
rather than raw counters that reset on reboot.

## Subcommands

```
monitor-agent version
monitor-agent enroll -master <url> -token <token>
monitor-agent run
```

## Config

The agent writes a small JSON config holding the master URL, its API key, and
the assigned host ID. Per the fleet convention it lives under the OS user-config
path, honoring `XDG_CONFIG_HOME`, and is overridable with `-config`.

## Build

```sh
./build.sh 1.1.2
```

Produces a fully **static** Linux x86_64 binary. `CGO_ENABLED=0` is required and
the build asserts it: a dynamic build ties the binary to the build host's glibc
and breaks on older distros.

Linux only, deliberately. Metrics collection reads Linux kernel interfaces, so
there is no macOS or Windows target.

Requires Go 1.26+. The version string is stamped in via
`-ldflags -X main.version=`.

## Licence

Proprietary. See [LICENSE](LICENSE). Not for redistribution.
