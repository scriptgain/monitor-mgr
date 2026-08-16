<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\MonitoredHost;
use App\Models\Trigger;
use Illuminate\Support\Collection;

/**
 * Turns the metrics stream into events.
 *
 * Runs in two places and nowhere else: at the end of agent ingest, where a fresh
 * sample can breach or recover a rule, and in the poller's sweep, which is the
 * only place that can notice a host saying nothing at all. Silence is the one
 * condition ingest can never report on itself.
 */
class TriggerEvaluator
{
    /** Evaluate every rule that applies to a host against its recent samples. */
    public static function forHost(MonitoredHost $host): void
    {
        foreach (self::rulesFor($host) as $trigger) {
            if ($trigger->isOfflineRule()) {
                continue; // Handled by the sweep: a live ingest proves it is not offline.
            }
            self::evaluate($host, $trigger);
        }
    }

    /**
     * The rules in force for a host, resolved by specificity.
     *
     * Three scopes can name the same metric: the whole fleet, a group the host
     * belongs to, and the host itself. Exactly one of them should apply, or a
     * fleet-wide "disk above 90" and a per-host "disk above 98" for the box that
     * always runs full would both fire and the narrower rule would be pointless.
     * The most specific wins: host beats group beats fleet.
     *
     * A host in two groups that both name the same metric is genuinely
     * ambiguous, and the tie is broken by the lower threshold, because the
     * operator who wrote the stricter rule is the one who wanted to hear about
     * it sooner.
     */
    public static function rulesFor(MonitoredHost $host): Collection
    {
        $groupIds = $host->groups()->pluck('host_groups.id')->all();

        $rules = Trigger::query()
            ->where('is_enabled', true)
            ->where(function ($q) use ($host, $groupIds) {
                $q->where(fn ($g) => $g->whereNull('monitored_host_id')->whereNull('host_group_id'))
                    ->orWhere('monitored_host_id', $host->id)
                    ->orWhereIn('host_group_id', $groupIds ?: [0]);
            })
            ->get();

        return $rules
            ->groupBy('metric')
            ->map(function (Collection $forMetric) {
                $best = $forMetric->max(fn (Trigger $t) => $t->specificity());

                return $forMetric->filter(fn (Trigger $t) => $t->specificity() === $best)
                    ->sortBy('threshold')
                    ->first();
            })
            ->values();
    }

    /**
     * Open or close the incident for one host and rule.
     *
     * The condition has to hold for the whole `for_seconds` window, judged from
     * the stored samples rather than from a counter, so a restart of the panel
     * does not reset how long a disk has been full.
     */
    public static function evaluate(MonitoredHost $host, Trigger $trigger): void
    {
        $latest = $host->metrics()->latest('captured_at')->first();
        if (! $latest) {
            return;
        }

        $value = MetricReader::value($latest, $trigger->metric);
        if ($value === null) {
            return; // The metric is not in this sample; say nothing rather than guess.
        }

        $open = self::openIncident($host, $trigger);

        if ($open) {
            if ($trigger->recovers($value)) {
                self::close($open, $host, $trigger, $value);
            }

            return;
        }

        if ($trigger->breaches($value) && self::sustained($host, $trigger)) {
            self::open($host, $trigger, $value);
        }
    }

    /**
     * The sweep for hosts that have gone quiet. A host with no agent_offline rule
     * in force is left alone; the UI still shows it offline, it just does not page.
     */
    public static function sweepOffline(): int
    {
        $opened = 0;
        $window = max(15, (int) config('monitor.offline_after_seconds', 90));

        foreach (MonitoredHost::query()->whereNotNull('api_key')->get() as $host) {
            $rule = self::rulesFor($host)->firstWhere('metric', 'agent_offline');
            if (! $rule) {
                continue;
            }

            $offline = $host->last_seen_at !== null && $host->last_seen_at->lt(now()->subSeconds($window));
            $open = self::openIncident($host, $rule);

            if ($offline && ! $open) {
                $late = (int) $host->last_seen_at->diffInSeconds(now());
                self::open($host, $rule, 1, "No agent report for {$late}s.");
                $opened++;
            } elseif (! $offline && $open && $host->last_seen_at) {
                self::close($open, $host, $rule, 0, 'The agent is reporting again.');
            }
        }

        return $opened;
    }

    private static function openIncident(MonitoredHost $host, Trigger $trigger): ?Incident
    {
        return Incident::query()
            ->where('monitored_host_id', $host->id)
            ->where('trigger_id', $trigger->id)
            ->whereNull('resolved_at')
            ->latest('started_at')
            ->first();
    }

    /** Has the rule breached on every sample across its whole window? */
    private static function sustained(MonitoredHost $host, Trigger $trigger): bool
    {
        $window = max(0, (int) $trigger->for_seconds);
        if ($window === 0) {
            return true;
        }

        $since = now()->subSeconds($window);
        $samples = $host->metrics()->where('captured_at', '>=', $since)->orderBy('captured_at')->get();
        if ($samples->isEmpty()) {
            return false;
        }

        // The window has to be covered, not merely touched. A host that enrolled
        // a minute ago cannot yet have been at 95% CPU for five minutes, and
        // treating its first report as proof would page on every new agent.
        //
        // Coverage is judged by history existing before the window rather than by
        // counting samples inside it: the agent's interval is configurable, and a
        // "sample count" test silently changes meaning when someone changes it.
        $hasHistoryBefore = $host->metrics()->where('captured_at', '<', $since)->exists();
        if (! $hasHistoryBefore) {
            return false;
        }

        foreach ($samples as $sample) {
            $value = MetricReader::value($sample, $trigger->metric);
            if ($value === null || ! $trigger->breaches($value)) {
                return false;
            }
        }

        return true;
    }

    private static function open(MonitoredHost $host, Trigger $trigger, float $value, ?string $cause = null): void
    {
        $cause ??= sprintf(
            '%s is %s (threshold %s) for %s',
            $trigger->metricLabel(),
            MetricReader::format($trigger->metric, $value),
            MetricReader::format($trigger->metric, $trigger->threshold),
            self::duration((int) $trigger->for_seconds),
        );

        $incident = Incident::create([
            'monitored_host_id' => $host->id,
            'trigger_id' => $trigger->id,
            'started_at' => now(),
            'cause' => mb_substr($cause, 0, 255),
            'severity' => $trigger->severity,
        ]);

        AuditLog::record('incident', "Trigger \"{$trigger->name}\" opened an incident for host {$host->name}");
        AlertDispatcher::incidentOpened($incident->setRelation('host', $host)->setRelation('trigger', $trigger));
    }

    private static function close(Incident $incident, MonitoredHost $host, Trigger $trigger, float $value, ?string $note = null): void
    {
        $note ??= $trigger->metricLabel().' is back to '.MetricReader::format($trigger->metric, $value)
            .' (recovers at '.MetricReader::format($trigger->metric, $trigger->recoveryValue()).')';

        $incident->update([
            'resolved_at' => now(),
            'duration_seconds' => (int) max(0, $incident->started_at?->diffInSeconds(now()) ?? 0),
        ]);

        AuditLog::record('incident', "Trigger \"{$trigger->name}\" resolved for host {$host->name}: {$note}");
        AlertDispatcher::incidentResolved($incident->setRelation('host', $host)->setRelation('trigger', $trigger));
    }

    private static function duration(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'one sample';
        }
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        $m = intdiv($seconds, 60);

        return $m < 60 ? "{$m}m" : intdiv($m, 60).'h '.($m % 60).'m';
    }
}
