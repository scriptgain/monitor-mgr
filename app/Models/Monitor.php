<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Monitor extends Model
{
    use OwnedByUser;

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
        'expected', 'status', 'last_checked_at', 'uptime_ratio', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'last_checked_at' => 'datetime',
            'uptime_ratio' => 'float',
            'port' => 'integer',
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

    /** The currently open (unresolved) incident, if any. */
    public function openIncident(): ?Incident
    {
        return $this->incidents()->whereNull('resolved_at')->latest('started_at')->first();
    }
}
