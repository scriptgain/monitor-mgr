<?php

namespace Tests\Feature;

use App\Http\Controllers\MaintenanceController;
use App\Models\HostMetric;
use App\Models\HostMetricRollup;
use App\Models\MonitoredHost;
use App\Models\Setting;
use App\Models\Trigger;
use App\Models\User;
use App\Services\HostHistory;
use App\Services\MetricReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MetricRollupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Trigger::query()->delete();
    }

    private function host(string $name = 'web01'): MonitoredHost
    {
        $host = MonitoredHost::create(['name' => $name]);
        $host->forceFill(['api_key' => hash('sha256', $name), 'last_seen_at' => now()])->save();

        return $host;
    }

    /** One sample every `stepSeconds`, going back `hours`, at a fixed cpu value. */
    private function samples(MonitoredHost $host, int $hours, float $cpu = 50, int $stepSeconds = 600): void
    {
        $rows = [];
        for ($t = $hours * 3600; $t > 0; $t -= $stepSeconds) {
            $rows[] = [
                'monitored_host_id' => $host->id,
                'captured_at' => now()->subSeconds($t),
                'cpu_pct' => $cpu,
                'mem_used' => 500, 'mem_total' => 1000,
                'swap_used' => 0, 'swap_total' => 0,
                'disk_used' => 250, 'disk_total' => 1000,
                'load1' => 1.0, 'load5' => 1.0, 'load15' => 1.0,
                'uptime' => 0, 'net_rx' => 100, 'net_tx' => 200,
                'detail' => null,
            ];
        }
        HostMetric::insert($rows);
    }

    public function test_it_writes_one_bucket_per_hour_with_correct_arithmetic(): void
    {
        $host = $this->host();
        $this->samples($host, hours: 5, cpu: 40);

        $this->artisan('monitor:rollup --buckets=hour')->assertExitCode(0);

        $rollups = HostMetricRollup::where('bucket', 'hour')->orderBy('bucket_start')->get();
        $this->assertGreaterThanOrEqual(4, $rollups->count());

        $first = $rollups->first();
        $this->assertSame(40.0, $first->cpu_pct_avg);
        $this->assertSame(40.0, $first->cpu_pct_max);
        $this->assertSame(500, (int) $first->mem_used_avg);
        $this->assertSame(1000, (int) $first->mem_total_avg);
        $this->assertGreaterThan(0, $first->sample_count);
    }

    /** An average alone cannot tell a flat hour from a spiky one. */
    public function test_min_and_max_survive_the_average(): void
    {
        $host = $this->host();
        $base = now()->subHours(2)->startOfHour();
        foreach ([0, 100, 50] as $i => $cpu) {
            HostMetric::create([
                'monitored_host_id' => $host->id,
                'captured_at' => $base->copy()->addMinutes($i * 10),
                'cpu_pct' => $cpu, 'mem_used' => 1, 'mem_total' => 2, 'disk_used' => 1, 'disk_total' => 2,
            ]);
        }

        $this->artisan('monitor:rollup --buckets=hour')->assertExitCode(0);

        $r = HostMetricRollup::where('bucket_start', $base)->first();
        $this->assertNotNull($r);
        $this->assertSame(50.0, $r->cpu_pct_avg);
        $this->assertSame(0.0, $r->cpu_pct_min);
        $this->assertSame(100.0, $r->cpu_pct_max);
    }

    public function test_it_is_idempotent(): void
    {
        $host = $this->host();
        $this->samples($host, hours: 4);

        $this->artisan('monitor:rollup')->assertExitCode(0);
        $after = HostMetricRollup::count();
        $this->artisan('monitor:rollup')->assertExitCode(0);

        $this->assertSame($after, HostMetricRollup::count());
    }

    /**
     * A host switched off for a week should leave a gap in the chart, not a run
     * of zeroes that reads as "idle" when it means "not there".
     */
    public function test_an_empty_hour_writes_no_bucket(): void
    {
        $host = $this->host();
        HostMetric::create([
            'monitored_host_id' => $host->id, 'captured_at' => now()->subHours(5),
            'cpu_pct' => 10, 'mem_used' => 1, 'mem_total' => 2, 'disk_used' => 1, 'disk_total' => 2,
        ]);

        $this->artisan('monitor:rollup --buckets=hour')->assertExitCode(0);

        $this->assertSame(1, HostMetricRollup::where('bucket', 'hour')->count());
    }

    /** The bucket in progress is left alone; averaging a partial hour is a lie. */
    public function test_the_current_hour_is_not_rolled_up(): void
    {
        $host = $this->host();
        HostMetric::create([
            'monitored_host_id' => $host->id, 'captured_at' => now(),
            'cpu_pct' => 10, 'mem_used' => 1, 'mem_total' => 2, 'disk_used' => 1, 'disk_total' => 2,
        ]);

        $this->artisan('monitor:rollup --buckets=hour')->assertExitCode(0);

        $this->assertSame(0, HostMetricRollup::count());
    }

    public function test_a_rollup_can_stand_in_for_a_sample(): void
    {
        $host = $this->host();
        $this->samples($host, hours: 3, cpu: 60);
        $this->artisan('monitor:rollup --buckets=hour')->assertExitCode(0);

        $sample = HostMetricRollup::first()->toSample();

        $this->assertSame(60.0, (float) $sample->cpu_pct);
        $this->assertSame(50.0, $sample->memPct());
        $this->assertSame(25.0, $sample->diskPct());
        // The per-disk blob is not aggregated, so a per-mount rule reads null
        // against history rather than a wrong number.
        $this->assertNull(MetricReader::value($sample, 'disk./.pct'));
    }

    // ---- retention ----

    /**
     * The safety property this whole table exists for. If the scheduler has been
     * down, the rollups are stale, and pruning raw samples past that point
     * destroys history that was never aggregated.
     */
    public function test_the_pruner_will_not_delete_past_the_last_rollup(): void
    {
        $host = $this->host();
        // Five days of hourly samples, so the one day retention floor has
        // something to bite on.
        $this->samples($host, hours: 5 * 24, stepSeconds: 3600);
        $total = HostMetric::count();

        $this->artisan('monitor:rollup --buckets=hour --since='.now()->subDays(6)->toDateString())->assertExitCode(0);

        // Now simulate a scheduler that has been down for three days: the recent
        // buckets do not exist, so those raw samples have never been aggregated.
        HostMetricRollup::where('bucket_start', '>', now()->subDays(3))->delete();
        $rolledTo = HostMetricRollup::where('bucket', 'hour')->max('bucket_start');
        $this->assertNotNull($rolledTo);

        Setting::put('host_metrics_days', '1');
        $deleted = MaintenanceController::pruneRawMetrics();

        $this->assertGreaterThan(0, $deleted, 'nothing was pruned at all');
        $this->assertGreaterThan(0, HostMetric::count(), 'everything was deleted despite stale rollups');
        $this->assertLessThan($total, HostMetric::count());

        // The guarantee: no sample that the rollups do not cover was deleted.
        // The last written bucket covers the hour starting at that timestamp.
        $safeUntil = Carbon::parse($rolledTo)->addHour();
        $this->assertSame(0, HostMetric::where('captured_at', '<', $safeUntil)->count(),
            'aggregated samples should have been pruned');
        $this->assertGreaterThan(0, HostMetric::where('captured_at', '>=', $safeUntil)->count(),
            'un-aggregated samples must survive');
    }

    public function test_a_host_with_no_rollups_is_never_pruned(): void
    {
        $host = $this->host();
        $this->samples($host, hours: 8);
        $before = HostMetric::count();

        Setting::put('host_metrics_days', '1');
        $this->assertSame(0, MaintenanceController::pruneRawMetrics());
        $this->assertSame($before, HostMetric::count());
    }

    public function test_rollups_are_pruned_on_their_own_schedule(): void
    {
        $host = $this->host();
        HostMetricRollup::create([
            'monitored_host_id' => $host->id, 'bucket' => 'hour',
            'bucket_start' => now()->subDays(200), 'sample_count' => 1,
        ]);
        HostMetricRollup::create([
            'monitored_host_id' => $host->id, 'bucket' => 'day',
            'bucket_start' => now()->subDays(200), 'sample_count' => 1,
        ]);

        Setting::put('rollup_hourly_days', '90');
        Setting::put('rollup_daily_days', '730');
        MaintenanceController::pruneRollups();

        $this->assertSame(0, HostMetricRollup::where('bucket', 'hour')->count());
        $this->assertSame(1, HostMetricRollup::where('bucket', 'day')->count(), 'the daily bucket was inside its window');
    }

    public function test_zero_days_keeps_rollups_forever(): void
    {
        $host = $this->host();
        HostMetricRollup::create([
            'monitored_host_id' => $host->id, 'bucket' => 'hour',
            'bucket_start' => now()->subDays(5000), 'sample_count' => 1,
        ]);

        Setting::put('rollup_hourly_days', '0');
        MaintenanceController::pruneRollups();

        $this->assertSame(1, HostMetricRollup::count());
    }

    /** Ingest must no longer delete anything; that was a DELETE per host per 30s. */
    public function test_agent_ingest_no_longer_prunes(): void
    {
        $host = $this->host();
        $this->samples($host, hours: 30 * 24, stepSeconds: 3600);
        $before = HostMetric::count();

        $plain = 'mon_'.str_repeat('a', 48);
        $host->forceFill(['api_key' => hash('sha256', $plain)])->save();

        $this->withToken($plain)->postJson('/api/agent/v1/metrics', [
            'cpu_pct' => 5, 'mem_used' => 1, 'mem_total' => 2, 'disk_used' => 1, 'disk_total' => 2,
        ])->assertOk();

        $this->assertSame($before + 1, HostMetric::count());
    }

    // ---- history endpoint ----

    public function test_a_long_range_reads_rollups_and_a_short_one_reads_raw(): void
    {
        $host = $this->host();
        $this->samples($host, hours: 10, cpu: 70);
        $this->artisan('monitor:rollup')->assertExitCode(0);

        $short = HostHistory::series($host, HostHistory::range('30m'));
        $this->assertSame('raw', $short['resolution']);

        $long = HostHistory::series($host, HostHistory::range('7d'));
        $this->assertSame('hour', $long['resolution']);
        $this->assertNotEmpty($long['history']['cpu']);
    }

    public function test_every_point_carries_a_timestamp(): void
    {
        $host = $this->host();
        $this->samples($host, hours: 2, cpu: 20);

        $series = HostHistory::series($host, HostHistory::range('30m'));
        $point = $series['history']['cpu'][0];

        $this->assertIsArray($point);
        $this->assertCount(2, $point);
        $this->assertNotFalse(strtotime($point[0]));
        $this->assertSame(20.0, $point[1]);
    }

    /** A young install has no rollups; an empty chart would look like an outage. */
    public function test_a_long_range_falls_back_to_raw_when_nothing_is_rolled_up(): void
    {
        $host = $this->host();
        $this->samples($host, hours: 3, cpu: 15);

        $series = HostHistory::series($host, HostHistory::range('30d'));

        $this->assertSame('raw', $series['resolution']);
        $this->assertNotEmpty($series['history']['cpu']);
    }

    public function test_an_unknown_range_falls_back_to_the_default(): void
    {
        $this->assertSame('30m', HostHistory::range('not-a-range')['key']);
    }

    public function test_the_endpoint_serves_the_range_and_the_gauges_stay_raw(): void
    {
        Setting::put('setup_complete', '1');
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('x'), 'role' => 'admin',
        ]);
        $host = $this->host();
        $this->samples($host, hours: 10, cpu: 80);
        $this->artisan('monitor:rollup')->assertExitCode(0);

        $response = $this->actingAs($admin)->getJson("/hosts/{$host->id}/metrics?range=7d");

        $response->assertOk()
            ->assertJsonPath('range', '7d')
            ->assertJsonPath('resolution', 'hour')
            // The gauge reads the newest raw sample, not an hourly average, so it
            // cannot disagree with the number a trigger fired on.
            ->assertJsonPath('latest.cpu_pct', 80);
    }
}
