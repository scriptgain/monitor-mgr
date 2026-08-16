<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Monitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private function heartbeat(array $attributes = []): Monitor
    {
        return Monitor::create(array_merge([
            'name' => 'Nightly backup',
            'type' => 'heartbeat',
            'target' => 'nightly-backup',
            'interval_seconds' => 3600,
            'timeout_seconds' => 30,
            'status' => 'up',
        ], $attributes));
    }

    public function test_a_ping_records_an_up_check(): void
    {
        $monitor = $this->heartbeat();

        $this->get('/api/hb/'.$monitor->heartbeatToken())
            ->assertOk()
            ->assertJson(['status' => 'up']);

        $this->assertDatabaseCount('checks', 1);
        $this->assertNotNull($monitor->fresh()->last_checked_at);
    }

    public function test_a_ping_with_a_failure_status_opens_an_incident(): void
    {
        $monitor = $this->heartbeat();

        $this->get('/api/hb/'.$monitor->heartbeatToken().'?status=down&message=exit+2')
            ->assertOk()
            ->assertJson(['status' => 'down']);

        $this->assertDatabaseCount('incidents', 1);
        $this->assertSame('exit 2', Incident::first()->cause);
    }

    public function test_a_recovery_ping_resolves_the_incident(): void
    {
        $monitor = $this->heartbeat();
        $this->get('/api/hb/'.$monitor->heartbeatToken().'?status=down');

        $this->get('/api/hb/'.$monitor->heartbeatToken())->assertOk();

        $this->assertNotNull(Incident::first()->resolved_at);
        $this->assertSame('up', $monitor->fresh()->status);
    }

    public function test_an_unknown_token_is_a_404(): void
    {
        $this->get('/api/hb/not-a-real-token')->assertNotFound();
    }

    public function test_the_token_is_stable_across_calls(): void
    {
        $monitor = $this->heartbeat();

        $this->assertSame($monitor->heartbeatToken(), $monitor->fresh()->heartbeatToken());
    }

    public function test_the_sweep_marks_a_silent_heartbeat_down(): void
    {
        $monitor = $this->heartbeat(['interval_seconds' => 60]);
        $monitor->forceFill(['last_checked_at' => now()->subMinutes(30)])->save();

        $this->artisan('monitor:poll')->assertExitCode(0);

        $this->assertSame('down', $monitor->fresh()->status);
        $this->assertDatabaseCount('incidents', 1);
    }

    public function test_the_sweep_leaves_a_fresh_heartbeat_alone(): void
    {
        $monitor = $this->heartbeat(['interval_seconds' => 3600]);
        $monitor->forceFill(['last_checked_at' => now()->subMinute()])->save();

        $this->artisan('monitor:poll')->assertExitCode(0);

        $this->assertSame('up', $monitor->fresh()->status);
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_the_sweep_ignores_a_heartbeat_that_has_never_been_pinged(): void
    {
        $this->heartbeat(['interval_seconds' => 60]);

        $this->artisan('monitor:poll')->assertExitCode(0);

        $this->assertDatabaseCount('incidents', 0);
    }
}
