<?php

namespace App\Services;

use App\Mail\IncidentAlert;
use App\Models\AlertContact;
use App\Models\AuditLog;
use App\Models\DowntimeWindow;
use App\Models\EscalationStep;
use App\Models\Incident;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers incident notifications.
 *
 * Contacts are resolved by ownership: a contact reaches an incident when it is
 * enabled and either has no owner (a panel-wide contact) or shares the
 * monitor's owner. On top of that, the single `notify_email` address from
 * Settings > Notifications still receives everything when that page is enabled,
 * so installs that predate alert contacts keep working.
 *
 * Every send is fail-soft. A dead webhook must never break the poller or leave
 * an incident half-recorded, so failures are logged and counted, not thrown.
 */
class AlertDispatcher
{
    public static function incidentOpened(Incident $incident): int
    {
        $subject = $incident->subject();
        if (! $subject) {
            return 0;
        }

        // A failed check reads DOWN; a breached threshold reads PROBLEM, because
        // a host at 91% memory is not down and saying so would train people to
        // ignore the word.
        $word = $incident->monitor_id ? 'DOWN' : strtoupper($incident->severityLabel());
        $title = '['.config('brand.name')."] {$word}: {$incident->subjectName()}";
        $body = trim(sprintf(
            "%s\n%s\nCause: %s\nStarted: %s\n%s",
            $incident->monitor_id
                ? $incident->subjectName().' is down.'
                : $incident->subjectName().' breached "'.($incident->trigger?->name ?: 'a threshold').'".',
            self::where($incident),
            $incident->cause ?: 'Check failed',
            $incident->started_at?->toDayDateTimeString() ?? now()->toDayDateTimeString(),
            self::link($incident),
        ));

        return self::fanOut($incident, 'opened', $title, $body);
    }

    public static function incidentResolved(Incident $incident): int
    {
        if (! $incident->subject()) {
            return 0;
        }

        $title = '['.config('brand.name')."] RECOVERED: {$incident->subjectName()}";
        $body = trim(sprintf(
            "%s is back to normal.\n%s\nProblem lasted: %s\n%s",
            $incident->subjectName(),
            self::where($incident),
            self::humanDuration((int) $incident->duration_seconds),
            self::link($incident),
        ));

        return self::fanOut($incident, 'resolved', $title, $body);
    }

    public static function incidentAcknowledged(Incident $incident): int
    {
        if (! $incident->subject()) {
            return 0;
        }

        $who = auth()->user()?->name ?: 'someone';
        $title = '['.config('brand.name')."] ACKNOWLEDGED: {$incident->subjectName()}";
        $body = trim(sprintf(
            "The open incident on %s was acknowledged by %s.\n%s",
            $incident->subjectName(),
            $who,
            self::link($incident),
        ));

        return self::fanOut($incident, 'acknowledged', $title, $body);
    }

    /**
     * Send one escalation step to its single contact.
     *
     * Deliberately not fanOut(): an escalation is aimed at one person who has
     * not been told yet, and re-notifying the whole original list every rung is
     * how a ladder turns into a siren.
     */
    public static function escalate(Incident $incident, EscalationStep $step): int
    {
        if (! $incident->subject() || ! $step->contact) {
            return 0;
        }
        if (DowntimeWindow::activeFor($incident)) {
            return 0;
        }

        $mins = (int) ($incident->started_at?->diffInMinutes(now()) ?? 0);
        $title = '['.config('brand.name')."] STILL OPEN: {$incident->subjectName()}";
        $body = trim(sprintf(
            "%s has been open for %d minute(s) with nobody acknowledging it.\n%s\nCause: %s\nSeverity: %s\n%s",
            $incident->subjectName(),
            $mins,
            self::where($incident),
            $incident->cause ?: 'Unknown',
            $incident->severityLabel(),
            self::link($incident),
        ));

        $ok = self::toContact($step->contact, $incident, 'escalated', $title, $body);
        AuditLog::record('incident', sprintf(
            'Escalation "%s" for incident #%d to %s: %s',
            $step->name, $incident->id, $step->contact->name, $ok ? 'sent' : 'failed'
        ));

        return $ok ? 1 : 0;
    }

    /** Contacts that should hear about this incident. */
    public static function contactsFor(Incident $incident): Collection
    {
        $ownerId = $incident->subject()?->user_id;

        return AlertContact::query()
            ->where('is_enabled', true)
            ->where(fn ($q) => $q->whereNull('user_id')->when($ownerId, fn ($q) => $q->orWhere('user_id', $ownerId)))
            ->get();
    }

    /** @return int the number of destinations that accepted the message */
    private static function fanOut(Incident $incident, string $event, string $title, string $body): int
    {
        // Planned downtime holds delivery, not collection. The incident is
        // already open and stays open; this only stops the phone ringing, and it
        // says so in the audit log so the quiet is explained rather than
        // mysterious. A resolution still goes out: "it is fixed" is never noise.
        if ($event !== 'resolved' && $window = DowntimeWindow::activeFor($incident)) {
            AuditLog::record('incident', sprintf(
                'Incident %s for %s suppressed by downtime window "%s"',
                $event, $incident->subjectName(), $window->name
            ));

            return 0;
        }

        $sent = 0;

        foreach (self::contactsFor($incident) as $contact) {
            if (self::toContact($contact, $incident, $event, $title, $body)) {
                $sent++;
            }
        }

        // The legacy single address from Settings > Notifications.
        if (Setting::get('notifications_enabled') === '1' && ($to = Setting::get('notify_email'))) {
            $sent += self::email($to, $title, $body) ? 1 : 0;
        }

        // Panel-wide integrations (Slack/Discord/Telegram/webhook in Settings).
        $sent += IntegrationNotifier::notify($title, $body);

        AuditLog::record('incident', sprintf(
            'Incident %s for monitor %s: %d notification(s) sent',
            $event, $incident->subjectName(), $sent
        ));

        return $sent;
    }

    private static function toContact(AlertContact $contact, Incident $incident, string $event, string $title, string $body): bool
    {
        $target = trim((string) $contact->target);
        if ($target === '') {
            return false;
        }

        try {
            return match ($contact->type) {
                'email' => self::email($target, $title, $body),
                'slack' => IntegrationNotifier::deliver('slack', $target, $title, $body),
                'webhook' => IntegrationNotifier::deliver('webhook', $target, $title, $body, self::payload($incident, $event)),
                'sms' => self::sms($target, $title.' '.strtok($body, "\n")),
                default => false,
            };
        } catch (\Throwable $e) {
            Log::warning("Alert contact {$contact->id} ({$contact->type}) failed: ".$e->getMessage());

            return false;
        }
    }

    private static function email(string $to, string $title, string $body): bool
    {
        try {
            Mail::to($to)->send(new IncidentAlert($title, $body));

            return true;
        } catch (\Throwable $e) {
            Log::warning("Alert email to {$to} failed: ".$e->getMessage());

            return false;
        }
    }

    /**
     * SMS goes through a generic HTTP gateway so any provider works: set the
     * URL under Settings > Integrations and the panel POSTs {to, message}.
     * Without one configured this is a no-op rather than a silent success.
     */
    private static function sms(string $to, string $message): bool
    {
        $url = Setting::get('sms_gateway_url');
        if (! $url) {
            Log::info('SMS alert skipped: no SMS gateway URL is configured.');

            return false;
        }

        $request = Http::timeout(8)->asJson();
        if ($token = Setting::get('sms_gateway_token')) {
            $request = $request->withToken($token);
        }

        return $request->post($url, ['to' => $to, 'message' => mb_substr($message, 0, 480)])->successful();
    }

    /** Machine-readable body for webhook contacts. */
    private static function payload(Incident $incident, string $event): array
    {
        $monitor = $incident->monitor;
        $host = $incident->host;

        return [
            'event' => "incident.{$event}",
            'incident' => [
                'id' => $incident->id,
                'severity' => $incident->severity,
                'started_at' => $incident->started_at?->toIso8601String(),
                'resolved_at' => $incident->resolved_at?->toIso8601String(),
                'acknowledged_at' => $incident->acknowledged_at?->toIso8601String(),
                'duration_seconds' => $incident->duration_seconds,
                'cause' => $incident->cause,
            ],
            'monitor' => $monitor ? [
                'id' => $monitor->id,
                'name' => $monitor->name,
                'type' => $monitor->type,
                'target' => $monitor->target,
                'status' => $monitor->status,
            ] : null,
            'host' => $host ? [
                'id' => $host->id,
                'name' => $host->name,
                'hostname' => $host->hostname,
                'status' => $host->effective_status,
            ] : null,
            'trigger' => $incident->trigger ? [
                'id' => $incident->trigger->id,
                'name' => $incident->trigger->name,
                'metric' => $incident->trigger->metric,
                'operator' => $incident->trigger->operator,
                'threshold' => $incident->trigger->threshold,
            ] : null,
        ];
    }

    /** One line naming what the incident is about, whichever kind it is. */
    private static function where(Incident $incident): string
    {
        if ($monitor = $incident->monitor) {
            return 'Target: '.$monitor->target.($monitor->port ? ':'.$monitor->port : '');
        }
        if ($host = $incident->host) {
            return 'Host: '.($host->hostname ?: $host->name);
        }

        return '';
    }

    private static function link(Incident $incident): string
    {
        return rescue(fn () => route('incidents.show', $incident), '', false);
    }

    private static function humanDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'less than a minute';
        }
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h > 0 ? "{$h}h {$m}m" : ($m > 0 ? "{$m}m {$s}s" : "{$s}s");
    }
}
