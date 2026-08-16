<?php

namespace App\Services;

use App\Checks\CheckResult;
use App\Models\AuditLog;
use App\Models\Check;
use App\Models\Incident;
use App\Models\Monitor;
use Illuminate\Support\Facades\Log;

/**
 * The one place a check result becomes state.
 *
 * Everything that produces a result funnels through here: the poller, the REST
 * API, the heartbeat endpoint, and the manual "record a check" form on the
 * monitor page. Keeping it single means incident open/close and notification
 * dispatch cannot drift apart between those four callers.
 */
class CheckRecorder
{
    /** How many recent checks the uptime ratio is averaged over. */
    private const UPTIME_WINDOW = 100;

    /**
     * Persist a result and drive the incident lifecycle.
     *
     * Returns null when the result was inconclusive, which means the panel
     * could not run the check at all. Those touch last_checked_at so the
     * monitor is not retried every single sweep, but write no check row and
     * never open an incident.
     */
    public static function record(Monitor $monitor, CheckResult $result): ?Check
    {
        if (! $result->conclusive) {
            Log::warning("Monitor {$monitor->id} ({$monitor->name}) could not be checked: {$result->message}");
            $monitor->forceFill(['last_checked_at' => now()])->save();

            return null;
        }

        $check = $monitor->checks()->create([
            'checked_at' => now(),
            'status' => $result->status,
            'response_time_ms' => $result->responseTimeMs,
            'status_code' => $result->statusCode,
            'message' => $result->message,
        ]);

        $monitor->last_checked_at = $check->checked_at;
        $monitor->status = $result->status;
        $monitor->uptime_ratio = self::uptimeRatio($monitor);
        $monitor->save();

        // Incident state follows the open incident rather than the previous
        // status column, so a monitor edited by hand cannot strand an incident.
        $open = $monitor->openIncident();

        if ($result->status === 'down' && ! $open) {
            $incident = Incident::create([
                'monitor_id' => $monitor->id,
                'started_at' => now(),
                'cause' => $result->message ?: 'Check failed',
            ]);
            AuditLog::record('incident', "Incident opened for monitor {$monitor->name}");
            AlertDispatcher::incidentOpened($incident->setRelation('monitor', $monitor));
        } elseif ($result->status === 'up' && $open) {
            $open->update([
                'resolved_at' => now(),
                'duration_seconds' => (int) max(0, $open->started_at?->diffInSeconds(now()) ?? 0),
            ]);
            AuditLog::record('incident', "Incident resolved for monitor {$monitor->name}");
            AlertDispatcher::incidentResolved($open->setRelation('monitor', $monitor));
        }

        return $check;
    }

    /**
     * Recompute and persist the stored ratio.
     *
     * Public because the maintenance sweep needs it: pruning checks changes the
     * window this is derived from, and without a refresh the column freezes at
     * whatever the last recorded check saw.
     */
    public static function refreshUptimeRatio(Monitor $monitor): float
    {
        $ratio = self::uptimeRatio($monitor);
        if ((float) $monitor->uptime_ratio !== $ratio) {
            $monitor->forceFill(['uptime_ratio' => $ratio])->save();
        }

        return $ratio;
    }

    /** Percentage of the recent check history that was up, to two decimals. */
    private static function uptimeRatio(Monitor $monitor): float
    {
        $recent = $monitor->checks()->latest('checked_at')->limit(self::UPTIME_WINDOW)->get(['status']);
        if ($recent->isEmpty()) {
            return 100.0;
        }

        return round($recent->where('status', 'up')->count() / $recent->count() * 100, 2);
    }
}
