<?php

namespace App\Services;

use App\Models\HostMetric;

/**
 * Resolves a trigger's metric name to a number on one sample.
 *
 * Percentages are computed here rather than stored, because the agent reports
 * used and total and those are the honest numbers to keep: a disk that grows
 * changes its percentage without any sample being wrong.
 */
class MetricReader
{
    public static function value(HostMetric $sample, string $metric): ?float
    {
        if (str_starts_with($metric, 'disk.')) {
            return self::mountPct($sample, $metric);
        }

        return match ($metric) {
            'cpu_pct' => (float) $sample->cpu_pct,
            'mem_pct' => self::pct((int) $sample->mem_used, (int) $sample->mem_total),
            'swap_pct' => self::pct((int) $sample->swap_used, (int) $sample->swap_total),
            'disk_pct' => self::pct((int) $sample->disk_used, (int) $sample->disk_total),
            'load1' => (float) $sample->load1,
            'load5' => (float) $sample->load5,
            'load15' => (float) $sample->load15,
            'net_rx' => (float) $sample->net_rx,
            'net_tx' => (float) $sample->net_tx,
            default => null,
        };
    }

    /** How the value reads in an incident cause. */
    public static function format(string $metric, float $value): string
    {
        if (str_ends_with($metric, '_pct') || str_starts_with($metric, 'disk.')) {
            return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.').'%';
        }
        if (str_starts_with($metric, 'net_')) {
            return self::bytes($value).'/s';
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * The per-disk detail the agent already sends, addressed by mount point.
     * Returns null rather than 0 when the mount is absent, so a rule about a
     * filesystem that has been unmounted goes quiet instead of reading as 0%
     * used and firing every "disk below" rule written against it.
     */
    private static function mountPct(HostMetric $sample, string $metric): ?float
    {
        if (! preg_match('/^disk\.(.+)\.pct$/', $metric, $m)) {
            return null;
        }
        $want = $m[1];

        foreach ((array) data_get($sample->detail, 'disks', []) as $disk) {
            $mount = (string) ($disk['mount'] ?? $disk['mountpoint'] ?? '');
            if ($mount !== '' && $mount === $want) {
                return self::pct((int) ($disk['used'] ?? 0), (int) ($disk['total'] ?? 0));
            }
        }

        return null;
    }

    private static function pct(int $used, int $total): float
    {
        return $total > 0 ? round($used / $total * 100, 2) : 0.0;
    }

    private static function bytes(float $n): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($n < 1024 || $unit === 'GB') {
                return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.').' '.$unit;
            }
            $n /= 1024;
        }

        return (string) $n;
    }
}
