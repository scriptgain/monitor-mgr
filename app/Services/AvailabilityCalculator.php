<?php

namespace App\Services;

use App\Models\DowntimeWindow;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitoredHost;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Availability over a real window of time.
 *
 * Computed from incidents rather than from checks, for three reasons. Incidents
 * carry a start and an end, so the answer is time weighted: an outage is a
 * duration, and a count of failed checks is not. Checks are pruned at thirty
 * days by default, so a ninety day figure cannot come from them at all. And the
 * existing `monitors.uptime_ratio` is the last hundred checks with no time bound
 * whatsoever, which on a sixty second monitor covers about an hour and a half
 * and was being printed to the public as "uptime".
 *
 * Planned downtime is subtracted. Counting a window you scheduled yourself
 * against your own availability is the difference between a number people act on
 * and one they argue with.
 */
class AvailabilityCalculator
{
    /** Named windows offered by the UI, in days. */
    public const PERIODS = ['24h' => 1, '7d' => 7, '30d' => 30, '90d' => 90];

    public const PERIOD_LABELS = ['24h' => '24 hours', '7d' => '7 days', '30d' => '30 days', '90d' => '90 days'];

    /**
     * @return array{
     *   uptime: float, downtime_seconds: int, excluded_seconds: int,
     *   counted_seconds: int, incidents: int, from: Carbon, to: Carbon, has_data: bool
     * }
     */
    public static function for(Monitor|MonitoredHost $subject, Carbon $from, Carbon $to): array
    {
        return self::compute(
            $subject, $from, $to,
            self::incidentsIn($subject, $from, $to),
            self::windows()
        );
    }

    /**
     * @param  Collection<int, Incident>  $allIncidents  already covering [$from, $to]
     * @param  Collection<int, DowntimeWindow>  $windows
     */
    private static function compute(
        Monitor|MonitoredHost $subject,
        Carbon $from,
        Carbon $to,
        Collection $allIncidents,
        Collection $windows
    ): array {
        // Normalized to whole seconds. `now()` carries microseconds while the
        // timestamps read back from the database do not, so an outage stored as
        // exactly one hour measures 3599 seconds against an un-normalized bound,
        // and every figure on the page ends up a second short.
        $from = $from->copy()->startOfSecond();
        $to = $to->copy()->startOfSecond();

        $total = max(1, (int) $from->diffInSeconds($to));
        $incidents = $allIncidents->filter(
            fn (Incident $i) => $i->started_at?->lt($to)
                && ($i->resolved_at === null || $i->resolved_at->gt($from))
        )->values();

        $downtime = 0;
        $excluded = 0;

        foreach ($incidents as $incident) {
            [$start, $end] = self::clamp($incident, $from, $to);
            if ($end->lte($start)) {
                continue;
            }

            $seconds = (int) $start->diffInSeconds($end);
            $planned = self::plannedSecondsFor($incident, $start, $end, $windows);

            $downtime += max(0, $seconds - $planned);
            $excluded += min($seconds, $planned);
        }

        // Time inside a downtime window is not counted as up OR down: it is
        // removed from the denominator. Leaving it in the denominator as "up"
        // would flatter a service that was switched off for the whole window.
        $counted = max(1, $total - $excluded);
        $uptime = max(0, min(100, ($counted - min($downtime, $counted)) / $counted * 100));

        return [
            'uptime' => round($uptime, 4),
            'downtime_seconds' => $downtime,
            'excluded_seconds' => $excluded,
            'counted_seconds' => $counted,
            'incidents' => $incidents->count(),
            'from' => $from,
            'to' => $to,
            // A subject that did not exist for the whole window, or has never
            // been checked, should not be reported as a confident 100%.
            'has_data' => self::hasDataFor($subject, $from),
        ];
    }

    /** Availability for each day of the window, oldest first. Used by the status page strip. */
    public static function daily(Monitor|MonitoredHost $subject, int $days, ?Carbon $endOfToday = null): array
    {
        $end = ($endOfToday ?? now())->copy()->endOfDay();

        // Incidents and windows are loaded ONCE for the whole span and the days
        // are computed in memory. Doing it per day meant 92 queries per subject,
        // which on a ten monitor public status page is over nine hundred for a
        // page anyone on the internet can request.
        $spanFrom = $end->copy()->subDays($days - 1)->startOfDay();
        $incidents = self::incidentsIn($subject, $spanFrom, $end);
        $windows = self::windows();

        $out = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $dayStart = $end->copy()->subDays($i)->startOfDay();
            $dayEnd = $dayStart->copy()->endOfDay();
            // The day in progress stops at now, so today is not reported as
            // mostly-perfect purely because most of it has not happened.
            if ($dayEnd->gt(now())) {
                $dayEnd = now();
            }
            if ($dayEnd->lte($dayStart)) {
                continue;
            }

            $result = self::compute($subject, $dayStart, $dayEnd, $incidents, $windows);
            $out[] = [
                'date' => $dayStart->toDateString(),
                'uptime' => $result['uptime'],
                'downtime_seconds' => $result['downtime_seconds'],
                'has_data' => $result['has_data'],
            ];
        }

        return $out;
    }

    /** Incidents that overlap the window at all, open ones included. */
    private static function incidentsIn(Monitor|MonitoredHost $subject, Carbon $from, Carbon $to): Collection
    {
        $column = $subject instanceof Monitor ? 'monitor_id' : 'monitored_host_id';

        return Incident::query()
            ->where($column, $subject->id)
            ->where('started_at', '<', $to)
            ->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>', $from))
            ->orderBy('started_at')
            ->get();
    }

    /**
     * An incident's overlap with the window.
     *
     * Both ends need clamping: one that began before the window contributes only
     * the part inside it, and one that is still open runs to the end of the
     * window rather than to null.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function clamp(Incident $incident, Carbon $from, Carbon $to): array
    {
        $start = $incident->started_at ?? $from;
        $end = $incident->resolved_at ?? $to;

        return [
            $start->lt($from) ? $from->copy() : $start->copy(),
            $end->gt($to) ? $to->copy() : $end->copy(),
        ];
    }

    /** Every enabled downtime window, loaded once per report rather than per day. */
    private static function windows(): Collection
    {
        return DowntimeWindow::query()->where('is_enabled', true)->with('group')->get();
    }

    /**
     * Seconds of this outage that fell inside an enabled downtime window.
     *
     * @param  Collection<int, DowntimeWindow>  $windows
     */
    private static function plannedSecondsFor(Incident $incident, Carbon $start, Carbon $end, Collection $windows): int
    {
        $planned = 0;

        foreach ($windows as $window) {
            if (! $window->coversSubject($incident)) {
                continue;
            }
            $planned = max($planned, $window->overlapSeconds($start, $end));
        }

        // max() rather than a sum: two overlapping windows covering the same
        // outage would otherwise excuse it twice and push uptime above 100.
        return min($planned, (int) $start->diffInSeconds($end));
    }

    /**
     * Whether the subject was around, and reporting, for the start of the window.
     *
     * Without this a host enrolled an hour ago reports a confident 100% over
     * ninety days, which is the most misleading number a status page can show.
     */
    private static function hasDataFor(Monitor|MonitoredHost $subject, Carbon $from): bool
    {
        $created = $subject->created_at;

        return $created !== null && $created->lte($from);
    }
}
