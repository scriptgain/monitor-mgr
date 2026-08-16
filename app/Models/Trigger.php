<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trigger extends Model
{
    use OwnedByUser;

    /**
     * Metrics a rule can be written against. The first group comes straight off
     * the sample; disk.<mount>.pct is resolved out of the per-disk JSON the agent
     * already sends, which is the closest thing here to Zabbix's low level
     * discovery without modelling every filesystem as its own item.
     */
    public const METRICS = [
        'cpu_pct' => 'CPU Used (%)',
        'mem_pct' => 'Memory Used (%)',
        'swap_pct' => 'Swap Used (%)',
        'disk_pct' => 'Disk Used (%)',
        'load1' => 'Load Average (1m)',
        'load5' => 'Load Average (5m)',
        'load15' => 'Load Average (15m)',
        'net_rx' => 'Network In (bytes/sec)',
        'net_tx' => 'Network Out (bytes/sec)',
        'agent_offline' => 'Agent Stopped Reporting',
    ];

    public const OPERATORS = ['>' => 'is above', '>=' => 'is at or above', '<' => 'is below', '<=' => 'is at or below'];

    /** Ordered lowest to highest. The order is the comparison. */
    public const SEVERITIES = ['info' => 'Info', 'warning' => 'Warning', 'average' => 'Average', 'high' => 'High', 'disaster' => 'Disaster'];

    protected $fillable = [
        'user_id', 'monitored_host_id', 'host_group_id', 'name', 'metric', 'operator', 'threshold',
        'recovery_threshold', 'for_seconds', 'severity', 'is_enabled', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'threshold' => 'float',
            'recovery_threshold' => 'float',
            'for_seconds' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(MonitoredHost::class, 'monitored_host_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(HostGroup::class, 'host_group_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function isOfflineRule(): bool
    {
        return $this->metric === 'agent_offline';
    }

    /** A rule with neither a host nor a group applies to every host. */
    public function isGlobal(): bool
    {
        return $this->monitored_host_id === null && $this->host_group_id === null;
    }

    /**
     * How specific this rule is, for resolving two rules on the same metric.
     * Higher wins: one host beats a group, a group beats the whole fleet.
     */
    public function specificity(): int
    {
        if ($this->monitored_host_id !== null) {
            return 2;
        }

        return $this->host_group_id !== null ? 1 : 0;
    }

    /** What the rule is aimed at, for the list. */
    public function targetName(): string
    {
        return $this->host?->name ?? $this->group?->name ?? 'Every host';
    }

    public function metricLabel(): string
    {
        if (str_starts_with($this->metric, 'disk.')) {
            return 'Disk Used (%) on '.$this->mountPoint();
        }

        return self::METRICS[$this->metric] ?? $this->metric;
    }

    /** The mount named by a disk.<mount>.pct metric, or null. */
    public function mountPoint(): ?string
    {
        if (! preg_match('/^disk\.(.+)\.pct$/', (string) $this->metric, $m)) {
            return null;
        }

        return $m[1];
    }

    public function severityLabel(): string
    {
        return self::SEVERITIES[$this->severity] ?? ucfirst((string) $this->severity);
    }

    /** Human summary used in incident causes and in the list. */
    public function condition(): string
    {
        if ($this->isOfflineRule()) {
            return 'the agent stops reporting';
        }

        $op = self::OPERATORS[$this->operator] ?? $this->operator;

        return trim($this->metricLabel().' '.$op.' '.rtrim(rtrim(number_format($this->threshold, 2, '.', ''), '0'), '.'));
    }

    /**
     * The value the metric must come back past to close an incident. Falling back
     * to the threshold is what an operator means by "no hysteresis", and it does
     * flap; the seeded defaults all set one.
     */
    public function recoveryValue(): float
    {
        return $this->recovery_threshold ?? $this->threshold;
    }

    /** True when `value` breaches this rule. */
    public function breaches(float $value): bool
    {
        return match ($this->operator) {
            '>' => $value > $this->threshold,
            '>=' => $value >= $this->threshold,
            '<' => $value < $this->threshold,
            '<=' => $value <= $this->threshold,
            default => false,
        };
    }

    /**
     * True when `value` has recovered past the recovery point. Deliberately not
     * `! breaches()`: with a recovery threshold set there is a band in between
     * where the metric is neither breaching nor recovered, and an incident has to
     * stay open across it rather than close and reopen every sample.
     */
    public function recovers(float $value): bool
    {
        $back = $this->recoveryValue();

        return match ($this->operator) {
            '>', '>=' => $value < $back,
            '<', '<=' => $value > $back,
            default => true,
        };
    }
}
