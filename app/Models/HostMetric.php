<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HostMetric extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'monitored_host_id', 'captured_at', 'cpu_pct', 'mem_used', 'mem_total',
        'swap_used', 'swap_total', 'disk_used', 'disk_total', 'load1', 'load5',
        'load15', 'uptime', 'net_rx', 'net_tx', 'detail',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'cpu_pct' => 'float',
            'load1' => 'float',
            'load5' => 'float',
            'load15' => 'float',
            'detail' => 'array',
        ];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(MonitoredHost::class, 'monitored_host_id');
    }

    public function memPct(): float
    {
        return $this->mem_total > 0 ? round($this->mem_used / $this->mem_total * 100, 1) : 0;
    }

    public function diskPct(): float
    {
        return $this->disk_total > 0 ? round($this->disk_used / $this->disk_total * 100, 1) : 0;
    }
}
