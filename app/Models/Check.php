<?php

namespace App\Models;

use App\Models\Concerns\OwnedViaMonitor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Check extends Model
{
    use OwnedViaMonitor;

    protected $fillable = [
        'monitor_id', 'checked_at', 'status', 'response_time_ms', 'status_code', 'message',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'response_time_ms' => 'integer',
            'status_code' => 'integer',
        ];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }
}
