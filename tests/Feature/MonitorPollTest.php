<?php

namespace Tests\Feature;

use App\Jobs\RunMonitorCheck;
use App\Models\Monitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MonitorPollTest extends TestCase
{
    use RefreshDatabase;

    private function monitor(array $attributes = []): Monitor
    {
        return Monitor::create(array_merge([
            'name' => 'Site',
            'type' => 'http',
            'target' => 'https://example.com',
            'interval_seconds' => 60,
            'timeout_seconds' => 5,
            'status' => 'up',
        ], $attributes));
    }

    public function test_a_monitor_that_has_never_been_checked_is_due(): void
    {
        Queue::fake();
        $monitor = $this->monitor();

        $this->artisan('monitor:poll')->assertExitCode(0);

        Queue::assertPushed(RunMonitorCheck::class, fn ($job) => $job->monitorId === $monitor->id);
    }

    public function test_a_recently_checked_monitor_is_not_due(): void
    {
        Queue::fake();
        $this->monitor()->forceFill(['last_checked_at' => now()])->save();

        $this->artisan('monitor:poll')->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_a_stale_monitor_is_due_again(): void
    {
        Queue::fake();
        $this->monitor(['interval_seconds' => 60])->forceFill(['last_checked_at' => now()->subMinutes(5)])->save();

        $this->artisan('monitor:poll')->assertExitCode(0);

        Queue::assertPushed(RunMonitorCheck::class);
    }

    public function test_paused_monitors_are_skipped(): void
    {
        Queue::fake();
        $this->monitor(['status' => 'paused']);

        $this->artisan('monitor:poll')->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_agent_monitors_are_never_polled(): void
    {
        Queue::fake();
        $this->monitor(['type' => 'agent', 'target' => 'web01']);

        $this->artisan('monitor:poll')->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_sync_mode_runs_the_check_and_records_it(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $monitor = $this->monitor();

        $this->artisan('monitor:poll --sync')->assertExitCode(0);

        $this->assertDatabaseCount('checks', 1);
        $this->assertSame('up', $monitor->fresh()->status);
    }

    public function test_the_job_records_a_result_for_its_monitor(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);
        $monitor = $this->monitor();

        (new RunMonitorCheck($monitor->id, 5))->handle();

        $this->assertSame('down', $monitor->fresh()->status);
        $this->assertDatabaseCount('incidents', 1);
    }

    public function test_the_job_no_ops_for_a_paused_monitor(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $monitor = $this->monitor(['status' => 'paused']);

        (new RunMonitorCheck($monitor->id, 5))->handle();

        $this->assertDatabaseCount('checks', 0);
    }
}
