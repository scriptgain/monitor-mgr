<?php

namespace Tests\Feature;

use App\Models\AlertContact;
use App\Models\HostMetric;
use App\Models\Incident;
use App\Models\MonitoredHost;
use App\Models\Trigger;
use App\Services\TriggerEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TriggerEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The seeded defaults are useful in production and noise in a test that
        // is asserting on one rule, so start from none.
        Trigger::query()->delete();
    }

    private function host(array $attributes = []): MonitoredHost
    {
        $host = MonitoredHost::create(array_merge(['name' => 'web01'], $attributes));
        $host->forceFill(['api_key' => hash('sha256', 'k'.$host->id), 'last_seen_at' => now()])->save();

        return $host;
    }

    private function trigger(array $attributes = []): Trigger
    {
        return Trigger::create(array_merge([
            'name' => 'CPU high',
            'metric' => 'cpu_pct',
            'operator' => '>',
            'threshold' => 90,
            'for_seconds' => 300,
            'severity' => 'high',
            'is_enabled' => true,
        ], $attributes));
    }

    /** Lay down samples covering the last $spanSeconds, all at $cpu. */
    private function samples(MonitoredHost $host, float $cpu, int $spanSeconds = 600, int $count = 6): void
    {
        $step = intdiv($spanSeconds, max(1, $count - 1));
        for ($i = $count - 1; $i >= 0; $i--) {
            HostMetric::create([
                'monitored_host_id' => $host->id,
                'captured_at' => now()->subSeconds($i * $step),
                'cpu_pct' => $cpu,
                'mem_used' => 1, 'mem_total' => 2,
                'disk_used' => 1, 'disk_total' => 2,
            ]);
        }
    }

    public function test_a_sustained_breach_opens_an_incident_with_the_trigger_severity(): void
    {
        Mail::fake();
        $host = $this->host();
        $trigger = $this->trigger();
        $this->samples($host, 95);

        TriggerEvaluator::forHost($host);

        $this->assertDatabaseCount('incidents', 1);
        $incident = Incident::first();
        $this->assertSame($host->id, $incident->monitored_host_id);
        $this->assertSame($trigger->id, $incident->trigger_id);
        $this->assertNull($incident->monitor_id);
        $this->assertSame('high', $incident->severity);
        $this->assertStringContainsString('95', $incident->cause);
    }

    public function test_it_does_not_open_a_second_incident_while_one_is_open(): void
    {
        $host = $this->host();
        $this->trigger();
        $this->samples($host, 95);

        TriggerEvaluator::forHost($host);
        TriggerEvaluator::forHost($host);

        $this->assertDatabaseCount('incidents', 1);
    }

    /**
     * The whole point of for_seconds. A host that enrolled a moment ago cannot
     * have been at 95% for five minutes, and treating one sample as proof would
     * page on every agent's first report.
     */
    public function test_one_breaching_sample_does_not_satisfy_a_five_minute_window(): void
    {
        $host = $this->host();
        $this->trigger(['for_seconds' => 300]);
        HostMetric::create([
            'monitored_host_id' => $host->id, 'captured_at' => now(),
            'cpu_pct' => 99, 'mem_used' => 1, 'mem_total' => 2, 'disk_used' => 1, 'disk_total' => 2,
        ]);

        TriggerEvaluator::forHost($host);

        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_a_dip_below_the_threshold_inside_the_window_stops_it_firing(): void
    {
        $host = $this->host();
        $this->trigger(['for_seconds' => 300]);
        $this->samples($host, 95);
        // One sample inside the 300s window drops. The condition has not held.
        HostMetric::where('monitored_host_id', $host->id)
            ->where('captured_at', '>=', now()->subSeconds(300))
            ->orderBy('captured_at')->limit(1)->update(['cpu_pct' => 10]);

        TriggerEvaluator::forHost($host);

        $this->assertDatabaseCount('incidents', 0);
    }

    /**
     * The coverage rule, stated on its own: samples that only reach back part of
     * the way through the window are not evidence the condition held for it.
     */
    public function test_a_host_with_no_history_before_the_window_does_not_fire(): void
    {
        $host = $this->host();
        $this->trigger(['for_seconds' => 300]);
        $this->samples($host, 95, spanSeconds: 120, count: 4);

        TriggerEvaluator::forHost($host);

        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_zero_for_seconds_fires_on_the_first_sample(): void
    {
        $host = $this->host();
        $this->trigger(['for_seconds' => 0]);
        HostMetric::create([
            'monitored_host_id' => $host->id, 'captured_at' => now(),
            'cpu_pct' => 99, 'mem_used' => 1, 'mem_total' => 2, 'disk_used' => 1, 'disk_total' => 2,
        ]);

        TriggerEvaluator::forHost($host);

        $this->assertDatabaseCount('incidents', 1);
    }

    /** Hysteresis: between the recovery point and the threshold, nothing changes. */
    public function test_it_stays_open_in_the_band_between_recovery_and_threshold(): void
    {
        $host = $this->host();
        $this->trigger(['recovery_threshold' => 80]);
        $this->samples($host, 95);
        TriggerEvaluator::forHost($host);
        $this->assertDatabaseCount('incidents', 1);

        // 85 is no longer breaching, but has not come back past 80 either.
        HostMetric::create([
            'monitored_host_id' => $host->id, 'captured_at' => now()->addSecond(),
            'cpu_pct' => 85, 'mem_used' => 1, 'mem_total' => 2, 'disk_used' => 1, 'disk_total' => 2,
        ]);
        TriggerEvaluator::forHost($host);

        $this->assertNull(Incident::first()->resolved_at);
    }

    public function test_coming_back_past_the_recovery_point_closes_it(): void
    {
        Mail::fake();
        AlertContact::create(['name' => 'Ops', 'type' => 'email', 'target' => 'ops@example.com', 'is_enabled' => true]);

        $host = $this->host();
        $this->trigger(['recovery_threshold' => 80]);
        $this->samples($host, 95);
        TriggerEvaluator::forHost($host);

        HostMetric::create([
            'monitored_host_id' => $host->id, 'captured_at' => now()->addSecond(),
            'cpu_pct' => 40, 'mem_used' => 1, 'mem_total' => 2, 'disk_used' => 1, 'disk_total' => 2,
        ]);
        TriggerEvaluator::forHost($host);

        $this->assertNotNull(Incident::first()->resolved_at);
        Mail::assertSentCount(2); // opened, then resolved
    }

    public function test_a_host_rule_replaces_the_global_rule_for_the_same_metric(): void
    {
        $host = $this->host();
        $this->trigger(['name' => 'Fleet CPU', 'threshold' => 90]);
        $this->trigger(['name' => 'This box runs hot', 'threshold' => 98, 'monitored_host_id' => $host->id]);
        $this->samples($host, 95);

        $rules = TriggerEvaluator::rulesFor($host);
        $this->assertCount(1, $rules);
        $this->assertSame('This box runs hot', $rules->first()->name);

        TriggerEvaluator::forHost($host);
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_a_disabled_rule_never_fires(): void
    {
        $host = $this->host();
        $this->trigger(['is_enabled' => false]);
        $this->samples($host, 99);

        TriggerEvaluator::forHost($host);

        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_a_metric_absent_from_the_sample_is_silent(): void
    {
        $host = $this->host();
        $this->trigger(['metric' => 'disk./mnt/nope.pct', 'threshold' => 1, 'for_seconds' => 0]);
        $this->samples($host, 10);

        TriggerEvaluator::forHost($host);

        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_a_below_rule_fires_and_recovers_the_other_way_round(): void
    {
        $host = $this->host();
        $this->trigger(['name' => 'Load collapsed', 'metric' => 'load1', 'operator' => '<', 'threshold' => 0.1, 'recovery_threshold' => 0.5, 'for_seconds' => 0]);
        HostMetric::create([
            'monitored_host_id' => $host->id, 'captured_at' => now(),
            'cpu_pct' => 1, 'load1' => 0.01, 'mem_used' => 1, 'mem_total' => 2, 'disk_used' => 1, 'disk_total' => 2,
        ]);

        TriggerEvaluator::forHost($host);
        $this->assertDatabaseCount('incidents', 1);

        HostMetric::create([
            'monitored_host_id' => $host->id, 'captured_at' => now()->addSecond(),
            'cpu_pct' => 1, 'load1' => 0.9, 'mem_used' => 1, 'mem_total' => 2, 'disk_used' => 1, 'disk_total' => 2,
        ]);
        TriggerEvaluator::forHost($host);
        $this->assertNotNull(Incident::first()->resolved_at);
    }

    public function test_the_sweep_opens_an_incident_for_a_host_that_stopped_reporting(): void
    {
        $host = $this->host();
        $this->trigger(['name' => 'Agent gone', 'metric' => 'agent_offline', 'for_seconds' => 0]);
        $host->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();

        $this->assertSame(1, TriggerEvaluator::sweepOffline());
        $this->assertDatabaseCount('incidents', 1);
        $this->assertStringContainsString('No agent report', Incident::first()->cause);

        // And it closes itself once the agent comes back.
        $host->forceFill(['last_seen_at' => now()])->save();
        TriggerEvaluator::sweepOffline();
        $this->assertNotNull(Incident::first()->resolved_at);
    }

    public function test_the_sweep_leaves_a_reporting_host_alone(): void
    {
        $host = $this->host();
        $this->trigger(['metric' => 'agent_offline', 'for_seconds' => 0]);

        $this->assertSame(0, TriggerEvaluator::sweepOffline());
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_a_host_that_never_enrolled_is_not_swept(): void
    {
        $host = MonitoredHost::create(['name' => 'never-enrolled']);
        $host->forceFill(['last_seen_at' => now()->subDay()])->save();
        $this->trigger(['metric' => 'agent_offline', 'for_seconds' => 0]);

        $this->assertSame(0, TriggerEvaluator::sweepOffline());
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_the_poll_command_runs_the_offline_sweep(): void
    {
        $host = $this->host();
        $this->trigger(['metric' => 'agent_offline', 'for_seconds' => 0]);
        $host->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();

        $this->artisan('monitor:poll')->assertExitCode(0);

        $this->assertDatabaseCount('incidents', 1);
    }
}
