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

**Collect server metrics, and act on them**
Agents report CPU, memory, disk, and load per host, so a slow server is visible
before it is a dead one. Triggers put thresholds on those numbers: a rule holds
for a set time before it fires, and has its own recovery value so a metric
hovering on the line does not flap an incident open and shut.

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

**The poller runs on a schedule.** `monitor:poll` fires every minute from the
scheduler, queues a check for every monitor whose interval has elapsed, and marks
heartbeat monitors that have gone quiet. HTTP, keyword, TCP, ping, DNS, and SSL
expiry monitors are executed by the panel with nothing installed on the target.
Two things have to be running for any of it to happen: `schedule:run` in cron and
a `queue:work` worker. The installer sets up both.

Server metrics still arrive by **push**, from an agent that dials out over HTTPS,
so monitored hosts need no inbound firewall rule. Heartbeat monitors are pinged by
your own cron at a URL the panel gives you, and an external checker can post its
own results to `POST /api/v1/checks`.

**Thresholds on server metrics work too.** A trigger is a rule such as "disk
above 90% for ten minutes". Breaching one opens an incident at the severity you
gave it and alerts your contacts; coming back past the recovery value closes it.
Rules apply to every host by default, so a fresh install is useful without
linking a template to anything, and a per-host rule overrides the fleet rule for
the same metric. A host that stops reporting is its own rule, evaluated by the
poller, because silence is the one thing an agent cannot report itself.

**What is not here yet:** escalation chains and on-call rotations, downtime
windows that suppress alerts, host groups and templates, and long term trends.
Metrics are kept raw for seven days and then deleted, so there is no history
beyond that yet.

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
