<?php

namespace App\Services;

use App\Mail\IncidentAlert;
use App\Models\AlertContact;
use App\Models\AuditLog;
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
        $monitor = $incident->monitor;
        if (! $monitor) {
            return 0;
        }

        $title = '['.config('brand.name')."] DOWN: {$monitor->name}";
        $body = trim(sprintf(
            "%s is down.\nTarget: %s\nCause: %s\nStarted: %s\n%s",
            $monitor->name,
            $monitor->target,
            $incident->cause ?: 'Check failed',
            $incident->started_at?->toDayDateTimeString() ?? now()->toDayDateTimeString(),
            self::link($incident),
        ));

        return self::fanOut($incident, 'opened', $title, $body);
    }

    public static function incidentResolved(Incident $incident): int
    {
        $monitor = $incident->monitor;
        if (! $monitor) {
            return 0;
        }

        $title = '['.config('brand.name')."] RECOVERED: {$monitor->name}";
        $body = trim(sprintf(
            "%s is back up.\nTarget: %s\nDown for: %s\n%s",
            $monitor->name,
            $monitor->target,
            self::humanDuration((int) $incident->duration_seconds),
            self::link($incident),
        ));

        return self::fanOut($incident, 'resolved', $title, $body);
    }

    public static function incidentAcknowledged(Incident $incident): int
    {
        $monitor = $incident->monitor;
        if (! $monitor) {
            return 0;
        }

        $who = auth()->user()?->name ?: 'someone';
        $title = '['.config('brand.name')."] ACKNOWLEDGED: {$monitor->name}";
        $body = trim(sprintf(
            "The open incident on %s was acknowledged by %s.\n%s",
            $monitor->name,
            $who,
            self::link($incident),
        ));

        return self::fanOut($incident, 'acknowledged', $title, $body);
    }

    /** Contacts that should hear about this incident. */
    public static function contactsFor(Incident $incident): Collection
    {
        $ownerId = $incident->monitor?->user_id;

        return AlertContact::query()
            ->where('is_enabled', true)
            ->where(fn ($q) => $q->whereNull('user_id')->when($ownerId, fn ($q) => $q->orWhere('user_id', $ownerId)))
            ->get();
    }

    /** @return int the number of destinations that accepted the message */
    private static function fanOut(Incident $incident, string $event, string $title, string $body): int
    {
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
            $event, $incident->monitor?->name ?? '#'.$incident->monitor_id, $sent
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

        return [
            'event' => "incident.{$event}",
            'incident' => [
                'id' => $incident->id,
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
        ];
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
