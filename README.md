# MonitorMGR

**Server and service monitoring with incidents, alerts, and public status pages.**
Self-hosted, by [ScriptGain](https://scriptgain.com).

**[Try the live demo →](https://monitor-demo.scriptgain.com)** No signup required.

## Who it's for

Sysadmins and MSPs who want their monitoring, incident history, and customer-facing
status page on infrastructure they control, without a per-check subscription or a
seat count.

## What it does

**Track what matters**
Monitors cover HTTP(S), TCP ports, ping, keyword-in-page, SSL certificate expiry,
DNS, heartbeats from your own cron jobs, and server agents reporting system health.

**Turn failures into incidents**
A failing monitor opens an incident with a timeline. Acknowledge it so the team
knows it is being handled, then resolve it. History is kept, so "how often does
this break" has an answer.

**Collect server metrics**
Agents report CPU, memory, disk, and load per host, so a slow server is visible
before it is a dead one.

**Tell customers before they ask**
Public status pages with the monitors you choose to expose. Alert contacts get
notified when something breaks.

**Run it like production**
Users and roles, two-factor authentication, an IP firewall with an escape hatch,
API tokens, a full audit log, database backups, host and SSL settings, and
in-place signed updates.

## Current state: read this before you buy

**Version 1.1.2.** Monitors, checks, incidents with acknowledge and resolve,
metrics, status pages, alert contacts, and the whole operations shell are built and
in production use.

**There is no polling loop yet.** MonitorMGR does not currently reach out on a
schedule to test your URLs and ports by itself. Check results and metrics arrive
by **push**: an agent enrolls and reports in over the API (`/api/.../ingest`),
heartbeat monitors are pinged by your own cron, and results can be recorded
through the API or the panel.

In practice that means: **heartbeat and agent monitoring work today; unattended
external uptime checking does not.** The scheduled checker is the next layer of
work. If you need "tell me when my website goes down" with nothing installed
anywhere, this is not there yet, and the demo will look like it is, because the
demo data is seeded.

## Install

Point a fresh Debian or Ubuntu server at your domain and run, as root:

```
curl -fsSL https://install.scriptgain.com | sudo bash -s -- monitor-mgr DOMAIN=monitor.example.com SSL=1 EMAIL=you@example.com
```

Then open `https://your.domain/setup` to create the first account and enter your
licence key.

## Where things live

| Surface | Path |
| --- | --- |
| Console | `/` |
| Public status pages | `/status/...` |
| First-run setup | `/setup` |
| Agent and API endpoints | `/api` |

## Running it

Monitors, alert contacts, status pages, and every operator setting are managed in
the console rather than in files on the server.

Maintenance tasks from the command line:

| Command | What it does |
| --- | --- |
| `php artisan monitor:maintenance` | Prunes telemetry and resolved incidents, trims the audit log. Runs hourly. |
| `php artisan license:check-online` | Re-validates your licence. |
| `php artisan app:update` | Applies a signed release. |
| `php artisan db-backup:run` | Backs up the database. |
| `php artisan firewall:clear` | Gets you back in if an IP rule locks you out. |

## Requirements

A Linux server with PHP 8.3 and MySQL or MariaDB. Metric history is the thing that
grows; the maintenance task prunes it on a retention window you set.

## Licensing

One activation per licence by default, validated against
`https://scriptgain.com/v1`. Buy or manage yours at
[scriptgain.com/products/monitormanager](https://scriptgain.com/products/monitormanager).
