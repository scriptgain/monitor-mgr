<?php

namespace Tests\Unit;

use App\Models\HostMetric;
use App\Services\MetricReader;
use PHPUnit\Framework\TestCase;

class MetricReaderTest extends TestCase
{
    private function sample(array $attributes = []): HostMetric
    {
        $m = new HostMetric;
        $m->forceFill(array_merge([
            'cpu_pct' => 12.5,
            'mem_used' => 3_000_000_000, 'mem_total' => 4_000_000_000,
            'swap_used' => 0, 'swap_total' => 0,
            'disk_used' => 45_000_000_000, 'disk_total' => 50_000_000_000,
            'load1' => 1.5, 'load5' => 1.1, 'load15' => 0.9,
            'net_rx' => 2048, 'net_tx' => 1024,
            'detail' => ['disks' => [
                ['mount' => '/', 'used' => 45_000_000_000, 'total' => 50_000_000_000],
                ['mount' => '/var', 'used' => 1_000_000_000, 'total' => 100_000_000_000],
            ]],
        ], $attributes));

        return $m;
    }

    public function test_it_reads_the_plain_columns(): void
    {
        $s = $this->sample();

        $this->assertSame(12.5, MetricReader::value($s, 'cpu_pct'));
        $this->assertSame(1.5, MetricReader::value($s, 'load1'));
        $this->assertSame(2048.0, MetricReader::value($s, 'net_rx'));
    }

    public function test_it_computes_percentages_from_used_and_total(): void
    {
        $s = $this->sample();

        $this->assertSame(75.0, MetricReader::value($s, 'mem_pct'));
        $this->assertSame(90.0, MetricReader::value($s, 'disk_pct'));
    }

    public function test_a_zero_total_is_zero_percent_not_a_division_error(): void
    {
        $s = $this->sample(['swap_total' => 0, 'swap_used' => 0]);

        $this->assertSame(0.0, MetricReader::value($s, 'swap_pct'));
    }

    public function test_it_reads_a_named_mount_out_of_the_detail_blob(): void
    {
        $s = $this->sample();

        $this->assertSame(90.0, MetricReader::value($s, 'disk./.pct'));
        $this->assertSame(1.0, MetricReader::value($s, 'disk./var.pct'));
    }

    /**
     * Null rather than zero. A rule about a filesystem that has been unmounted
     * must go quiet, not read as 0% used and fire every "disk below" rule.
     */
    public function test_an_absent_mount_is_null(): void
    {
        $this->assertNull(MetricReader::value($this->sample(), 'disk./mnt/backup.pct'));
    }

    public function test_an_unknown_metric_is_null(): void
    {
        $this->assertNull(MetricReader::value($this->sample(), 'not_a_metric'));
    }

    public function test_formatting_matches_the_metric(): void
    {
        $this->assertSame('91.5%', MetricReader::format('cpu_pct', 91.5));
        $this->assertSame('90%', MetricReader::format('disk./.pct', 90.0));
        $this->assertSame('1.5', MetricReader::format('load1', 1.5));
        $this->assertStringContainsString('/s', MetricReader::format('net_rx', 2048));
    }
}
