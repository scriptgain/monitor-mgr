<?php

namespace App\Models;

use App\Models\Concerns\OwnedViaMonitor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Incident extends Model
{
    use OwnedViaMonitor;

    protected $fillable = [
        'monitor_id', 'started_at', 'resolved_at', 'duration_seconds', 'cause', 'acknowledged_at',
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

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }
}
