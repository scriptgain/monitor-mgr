<?php

namespace App\Models;

use App\Models\Concerns\HasTags;
use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Monitor extends Model
{
    use HasTags, OwnedByUser;

    public const TYPES = [
        'http' => 'HTTP(S)',
        'tcp' => 'TCP Port',
        'ping' => 'Ping',
        'keyword' => 'Keyword',
        'ssl' => 'SSL Certificate',
        'dns' => 'DNS',
        'heartbeat' => 'Heartbeat',
        'agent' => 'Server Agent',
    ];

    public const STATUSES = [
        'up' => 'Up',
        'down' => 'Down',
        'paused' => 'Paused',
    ];

    protected $fillable = [
        'user_id', 'name', 'type', 'target', 'port', 'interval_seconds', 'timeout_seconds',
        'expected', 'status', 'last_checked_at', 'uptime_ratio', 'notes', 'tags',
    ];

    protected function casts(): array
    {
        return [
            'last_checked_at' => 'datetime',
            'uptime_ratio' => 'float',
            'port' => 'integer',
            'tags' => 'array',
            'interval_seconds' => 'integer',
            'timeout_seconds' => 'integer',
        ];
    }

    public function checks(): HasMany
    {
        return $this->hasMany(Check::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(Metric::class);
    }

    /** Pivot table name pinned to `monitor_status_page`. */
    public function statusPages(): BelongsToMany
    {
        return $this->belongsToMany(StatusPage::class, 'monitor_status_page');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function isAgentType(): bool
    {
        return $this->type === 'agent';
    }

    public function isHeartbeatType(): bool
    {
        return $this->type === 'heartbeat';
    }

    /**
     * The token in this monitor's ping URL, minted on first use.
     *
     * Lazy rather than minted at creation so monitors that predate the column,
     * and monitors switched to heartbeat after the fact, both get one without a
     * backfill migration.
     */
    public function heartbeatToken(): string
    {
        if (! $this->heartbeat_token) {
            $this->forceFill(['heartbeat_token' => Str::random(40)])->save();
        }

        return (string) $this->heartbeat_token;
    }

    /** The URL an external cron job pings to say "I ran". */
    public function heartbeatUrl(): string
    {
        return url('/api/hb/'.$this->heartbeatToken());
    }

    /**
     * Seconds of silence after which a heartbeat monitor counts as missed. The
     * grace period stops a job that runs a few seconds late from paging anyone.
     */
    public function heartbeatDeadline(): int
    {
        return max(30, (int) $this->interval_seconds) + max(0, (int) config('monitor.poll.heartbeat_grace_seconds', 60));
    }

    /** The currently open (unresolved) incident, if any. */
    public function openIncident(): ?Incident
    {
        return $this->incidents()->whereNull('resolved_at')->latest('started_at')->first();
    }
}
