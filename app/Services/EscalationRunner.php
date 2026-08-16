<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DowntimeWindow;
use App\Models\EscalationStep;
use App\Models\Incident;
use App\Models\IncidentEscalation;

/**
 * Walks the escalation ladder for incidents nobody has picked up.
 *
 * Runs once a minute from the scheduler. Three things stop it, and they are the
 * three things that should: acknowledging the incident, resolving it, and a
 * downtime window covering its subject.
 */
class EscalationRunner
{
    /** @return int the number of escalation notifications sent */
    public static function run(): int
    {
        $steps = EscalationStep::query()->where('is_enabled', true)->with('contact')->orderBy('after_minutes')->get();
        if ($steps->isEmpty()) {
            return 0;
        }

        // Acknowledged is excluded in the query, not filtered later: acking is
        // the whole contract of this feature and it should be impossible to
        // escalate past it by accident.
        $incidents = Incident::query()
            ->whereNull('resolved_at')
            ->whereNull('acknowledged_at')
            ->with(['monitor', 'host', 'trigger'])
            ->get();

        $sent = 0;
        foreach ($incidents as $incident) {
            if ($window = DowntimeWindow::activeFor($incident)) {
                AuditLog::record('incident', sprintf(
                    'Escalation for incident #%d held by downtime window "%s"', $incident->id, $window->name
                ));

                continue;
            }
            foreach ($steps as $step) {
                if (self::fire($incident, $step)) {
                    $sent++;
                }
            }
        }

        return $sent;
    }

    /** Send one step for one incident if it is due and has not already gone. */
    private static function fire(Incident $incident, EscalationStep $step): bool
    {
        if (! $step->contact || ! $step->contact->is_enabled) {
            return false;
        }
        if (! $step->coversSeverity($incident->severity)) {
            return false;
        }

        $openedMinutesAgo = (int) ($incident->started_at?->diffInMinutes(now()) ?? 0);
        if ($openedMinutesAgo < $step->after_minutes) {
            return false;
        }

        $record = IncidentEscalation::query()
            ->where('incident_id', $incident->id)
            ->where('escalation_step_id', $step->id)
            ->first();

        if ($record) {
            // Already sent. Only a repeating step goes again, and only once its
            // interval has passed since the last send.
            if (! $step->repeat_minutes) {
                return false;
            }
            if ($record->sent_at->gt(now()->subMinutes($step->repeat_minutes))) {
                return false;
            }
        }

        $delivered = AlertDispatcher::escalate($incident, $step);
        if ($delivered === 0) {
            return false;
        }

        if ($record) {
            $record->forceFill(['sent_at' => now(), 'sent_count' => $record->sent_count + 1])->save();
        } else {
            IncidentEscalation::create([
                'incident_id' => $incident->id,
                'escalation_step_id' => $step->id,
                'sent_at' => now(),
                'sent_count' => 1,
            ]);
        }

        return true;
    }
}
