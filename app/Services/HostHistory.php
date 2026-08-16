<?php

namespace App\Services;

use App\Models\HostMetric;
use App\Models\HostMetricRollup;
use App\Models\MonitoredHost;

/**
 * Builds a host's metric history for a time range, choosing raw samples or
 * rollups by what the range actually needs.
 *
 * The choice is not "whichever exists". Raw samples are pruned at seven days, so
 * anything longer has to read rollups; and a 30 day chart from raw samples would
 * be 86,400 points rendered into 300 pixels, which is slower and no more true
 * than 720 hourly ones.
 *
 * Every series is returned as [timestamp, value] pairs. The old endpoint
 * returned bare numbers, which is why the chart's caption could only honestly
 * say "last N samples" rather than name a period.
 */
class HostHistory
{
    /**
     * key => [label, seconds, source, points]
     *
     * `source` is the coarsest resolution that still fills the chart. `points`
     * caps what is sent to the browser: past a few hundred, an SVG path gains
     * bytes and loses nothing a reader can see.
     */
    private const RANGES = [
        '30m' => ['Last 30 Minutes', 1800, 'raw', 60],
        '6h' => ['Last 6 Hours', 21600, 'raw', 180],
        '24h' => ['Last 24 Hours', 86400, 'hour', 24],
        '7d' => ['Last 7 Days', 604800, 'hour', 168],
        '30d' => ['Last 30 Days', 2592000, 'day', 30],
        '90d' => ['Last 90 Days', 7776000, 'day', 90],
    ];

    public static function range(string $key): array
    {
        $key = isset(self::RANGES[$key]) ? $key : '30m';
        [$label, $seconds, $source, $points] = self::RANGES[$key];

        return compact('key', 'label', 'seconds', 'source', 'points');
    }

    /** The range picker's options, for the UI. */
    public static function options(): array
    {
        $out = [];
        foreach (self::RANGES as $key => [$label]) {
            $out[] = ['key' => $key, 'label' => $label];
        }

        return $out;
    }

    /**
     * @return array{resolution: string, history: array<string, array<int, array{0: string, 1: float}>>}
     */
    public static function series(MonitoredHost $host, array $range): array
    {
        $since = now()->subSeconds($range['seconds']);

        if ($range['source'] === 'raw') {
            return [
                'resolution' => 'raw',
                'history' => self::fromRaw($host, $since, $range['points']),
            ];
        }

        $rollups = HostMetricRollup::query()
            ->where('monitored_host_id', $host->id)
            ->where('bucket', $range['source'])
            ->where('bucket_start', '>=', $since)
            ->orderBy('bucket_start')
            ->get();

        // A young install has no rollups yet, and an empty chart would look like
        // an outage. Fall back to raw and say so, rather than showing nothing.
        if ($rollups->isEmpty()) {
            return [
                'resolution' => 'raw',
                'history' => self::fromRaw($host, $since, $range['points']),
            ];
        }

        return [
            'resolution' => $range['source'],
            'history' => [
                'cpu' => $rollups->map(fn ($r) => [self::stamp($r->bucket_start), round($r->cpu_pct_avg, 1)])->all(),
                'mem' => $rollups->map(fn ($r) => [self::stamp($r->bucket_start), self::pct($r->mem_used_avg, $r->mem_total_avg)])->all(),
                'disk' => $rollups->map(fn ($r) => [self::stamp($r->bucket_start), self::pct($r->disk_used_avg, $r->disk_total_avg)])->all(),
            ],
        ];
    }

    private static function fromRaw(MonitoredHost $host, $since, int $points): array
    {
        $samples = HostMetric::query()
            ->where('monitored_host_id', $host->id)
            ->where('captured_at', '>=', $since)
            ->orderByDesc('captured_at')
            ->limit(max(1, $points))
            ->get()
            ->reverse()
            ->values();

        return [
            'cpu' => $samples->map(fn ($m) => [self::stamp($m->captured_at), round((float) $m->cpu_pct, 1)])->all(),
            'mem' => $samples->map(fn ($m) => [self::stamp($m->captured_at), $m->memPct()])->all(),
            'disk' => $samples->map(fn ($m) => [self::stamp($m->captured_at), $m->diskPct()])->all(),
        ];
    }

    private static function pct(int|float $used, int|float $total): float
    {
        return $total > 0 ? round($used / $total * 100, 1) : 0.0;
    }

    private static function stamp($at): string
    {
        return $at?->toIso8601String() ?? '';
    }
}
