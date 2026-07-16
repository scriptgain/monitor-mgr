<?php

namespace App\Models;

use App\Models\Concerns\OwnedViaMonitor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Metric extends Model
{
    use OwnedViaMonitor;

    protected $fillable = [
        'monitor_id', 'cpu_pct', 'mem_pct', 'disk_pct', 'load1', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'cpu_pct' => 'float',
            'mem_pct' => 'float',
            'disk_pct' => 'float',
            'load1' => 'float',
            'recorded_at' => 'datetime',
        ];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }
}
