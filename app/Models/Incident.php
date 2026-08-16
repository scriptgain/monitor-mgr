<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An incident is an open problem with one subject: a monitor whose check failed,
 * or a host whose metrics breached a trigger. It deliberately does not use the
 * OwnedViaMonitor trait, because that trait can only see monitors and a host
 * incident under it would be visible to nobody but an admin.
 */
class Incident extends Model
{
    protected $fillable = [
        'monitor_id', 'monitored_host_id', 'trigger_id', 'started_at', 'resolved_at',
        'duration_seconds', 'cause', 'acknowledged_at', 'severity',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(MonitoredHost::class, 'monitored_host_id');
    }

    public function trigger(): BelongsTo
    {
        return $this->belongsTo(Trigger::class);
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }

    /** An incident belongs to a monitor or a host, never both. */
    public function subject(): Monitor|MonitoredHost|null
    {
        return $this->monitor_id ? $this->monitor : $this->host;
    }

    public function subjectName(): string
    {
        return $this->subject()?->name ?? 'Unknown';
    }

    /** Where the incident points back to, for a link in an alert or the list. */
    public function subjectRoute(): ?string
    {
        $subject = $this->subject();
        if (! $subject) {
            return null;
        }

        return $subject instanceof Monitor
            ? route('monitors.show', $subject)
            : route('hosts.show', $subject);
    }

    public function severityLabel(): string
    {
        return Trigger::SEVERITIES[$this->severity] ?? ucfirst((string) $this->severity);
    }

    /** Badge colour, shared by the list, the detail page and the dashboard. */
    public function severityColor(): string
    {
        return match ($this->severity) {
            'info' => 'neutral',
            'warning' => 'warning',
            'high', 'disaster' => 'danger',
            default => 'danger',
        };
    }

    /**
     * Visibility follows whichever parent the incident actually has. The
     * OwnedViaMonitor trait only knows about monitors, so a host incident would
     * otherwise be invisible to its owner and visible to nobody but an admin.
     */
    public function isVisibleTo(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        return (bool) $this->subject()?->isVisibleTo($user);
    }

    public function scopeVisibleTo($query, ?User $user)
    {
        if ($user && ! $user->isAdmin()) {
            $query->where(function ($q) use ($user) {
                $q->whereHas('monitor', fn ($m) => $m->visibleTo($user))
                    ->orWhereHas('host', fn ($h) => $h->visibleTo($user));
            });
        }

        return $query;
    }
}
