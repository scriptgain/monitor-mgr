<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One aggregated bucket of a host's metrics.
 *
 * Deliberately shaped so that it can stand in for a HostMetric wherever a chart
 * or a threshold only needs a number: `toSample()` hands back an unsaved
 * HostMetric carrying the averages, which means MetricReader and every existing
 * percentage helper work against rolled up history without knowing it is rolled
 * up.
 */
class HostMetricRollup extends Model
{
    public const BUCKETS = ['hour', 'day'];

    protected $fillable = [
        'monitored_host_id', 'bucket', 'bucket_start', 'sample_count',
        'cpu_pct_avg', 'cpu_pct_min', 'cpu_pct_max',
        'load1_avg', 'load1_min', 'load1_max',
        'load5_avg', 'load5_min', 'load5_max',
        'load15_avg', 'load15_min', 'load15_max',
        'mem_used_avg', 'mem_used_max', 'mem_total_avg',
        'swap_used_avg', 'swap_used_max', 'swap_total_avg',
        'disk_used_avg', 'disk_used_max', 'disk_total_avg',
        'net_rx_avg', 'net_rx_max', 'net_tx_avg', 'net_tx_max',
    ];

    protected function casts(): array
    {
        return [
            'bucket_start' => 'datetime',
            'sample_count' => 'integer',
            'cpu_pct_avg' => 'float', 'cpu_pct_min' => 'float', 'cpu_pct_max' => 'float',
            'load1_avg' => 'float', 'load1_min' => 'float', 'load1_max' => 'float',
            'load5_avg' => 'float', 'load5_min' => 'float', 'load5_max' => 'float',
            'load15_avg' => 'float', 'load15_min' => 'float', 'load15_max' => 'float',
        ];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(MonitoredHost::class, 'monitored_host_id');
    }

    /**
     * Aggregate a bucket's raw samples into the attribute set for one row.
     *
     * Done in PHP rather than SQL on purpose. The same choice is made in
     * DashboardController and DowntimeWindow, and for the same reason: the date
     * arithmetic and the aggregate functions this would need are not portable
     * between MySQL and the SQLite the tests run on.
     *
     * @param  Collection<int, HostMetric>  $samples
     */
    public static function summarize(int $hostId, string $bucket, Carbon $start, Collection $samples): array
    {
        $row = [
            'monitored_host_id' => $hostId,
            'bucket' => $bucket,
            'bucket_start' => $start,
            'sample_count' => $samples->count(),
        ];

        foreach (['cpu_pct', 'load1', 'load5', 'load15'] as $metric) {
            $values = $samples->map(fn (HostMetric $m) => (float) $m->{$metric});
            $row[$metric.'_avg'] = round((float) $values->avg(), 3);
            $row[$metric.'_min'] = round((float) $values->min(), 3);
            $row[$metric.'_max'] = round((float) $values->max(), 3);
        }

        foreach (['mem', 'swap', 'disk'] as $metric) {
            $used = $samples->map(fn (HostMetric $m) => (int) $m->{$metric.'_used'});
            $total = $samples->map(fn (HostMetric $m) => (int) $m->{$metric.'_total'});
            $row[$metric.'_used_avg'] = (int) round((float) $used->avg());
            $row[$metric.'_used_max'] = (int) $used->max();
            $row[$metric.'_total_avg'] = (int) round((float) $total->avg());
        }

        foreach (['net_rx', 'net_tx'] as $metric) {
            $values = $samples->map(fn (HostMetric $m) => (int) $m->{$metric});
            $row[$metric.'_avg'] = (int) round((float) $values->avg());
            $row[$metric.'_max'] = (int) $values->max();
        }

        return $row;
    }

    /**
     * An unsaved HostMetric carrying this bucket's averages.
     *
     * The point is reuse: MetricReader::value(), HostMetric::memPct() and the
     * chart code all take a HostMetric, and none of them need to learn what a
     * rollup is. The per-disk `detail` blob is not aggregated, so a
     * disk.<mount>.pct rule reads null against history rather than a wrong
     * number, which is the behavior MetricReader already treats as "say nothing".
     */
    public function toSample(): HostMetric
    {
        $sample = new HostMetric;
        $sample->forceFill([
            'monitored_host_id' => $this->monitored_host_id,
            'captured_at' => $this->bucket_start,
            'cpu_pct' => $this->cpu_pct_avg,
            'mem_used' => $this->mem_used_avg,
            'mem_total' => $this->mem_total_avg,
            'swap_used' => $this->swap_used_avg,
            'swap_total' => $this->swap_total_avg,
            'disk_used' => $this->disk_used_avg,
            'disk_total' => $this->disk_total_avg,
            'load1' => $this->load1_avg,
            'load5' => $this->load5_avg,
            'load15' => $this->load15_avg,
            'net_rx' => $this->net_rx_avg,
            'net_tx' => $this->net_tx_avg,
            'detail' => null,
        ]);

        return $sample;
    }
}
