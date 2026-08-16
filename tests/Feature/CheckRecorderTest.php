<?php

namespace Tests\Feature;

use App\Checks\CheckResult;
use App\Models\AlertContact;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\User;
use App\Services\CheckRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CheckRecorderTest extends TestCase
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

    public function test_a_down_result_writes_a_check_and_opens_one_incident(): void
    {
        $monitor = $this->monitor();

        CheckRecorder::record($monitor, CheckResult::down('Unexpected status 500.', 120, 500));

        $this->assertDatabaseCount('checks', 1);
        $this->assertDatabaseCount('incidents', 1);
        $this->assertSame('down', $monitor->fresh()->status);
        $this->assertSame('Unexpected status 500.', Incident::first()->cause);

        // A second down result must not open a second incident.
        CheckRecorder::record($monitor->fresh(), CheckResult::down('Still down.', 120, 500));

        $this->assertDatabaseCount('checks', 2);
        $this->assertDatabaseCount('incidents', 1);
    }

    public function test_an_up_result_resolves_the_open_incident(): void
    {
        $monitor = $this->monitor();
        CheckRecorder::record($monitor, CheckResult::down('Unexpected status 500.'));

        CheckRecorder::record($monitor->fresh(), CheckResult::up(90, 200));

        $incident = Incident::first();
        $this->assertNotNull($incident->resolved_at);
        $this->assertGreaterThanOrEqual(0, $incident->duration_seconds);
        $this->assertSame('up', $monitor->fresh()->status);
    }

    public function test_an_inconclusive_result_records_nothing_and_opens_no_incident(): void
    {
        $monitor = $this->monitor();

        $check = CheckRecorder::record($monitor, CheckResult::unavailable('fping is not installed.'));

        $this->assertNull($check);
        $this->assertDatabaseCount('checks', 0);
        $this->assertDatabaseCount('incidents', 0);
        $this->assertSame('up', $monitor->fresh()->status);
        // It still counts as an attempt, so the sweep does not spin on it.
        $this->assertNotNull($monitor->fresh()->last_checked_at);
    }

    public function test_the_uptime_ratio_follows_the_check_history(): void
    {
        $monitor = $this->monitor();

        CheckRecorder::record($monitor, CheckResult::up());
        CheckRecorder::record($monitor->fresh(), CheckResult::down('nope'));
        CheckRecorder::record($monitor->fresh(), CheckResult::up());
        CheckRecorder::record($monitor->fresh(), CheckResult::up());

        $this->assertSame(75.0, round($monitor->fresh()->uptime_ratio, 2));
    }

    public function test_opening_an_incident_notifies_alert_contacts(): void
    {
        Mail::fake();
        Http::fake(['*' => Http::response('', 200)]);

        AlertContact::create(['name' => 'Ops mail', 'type' => 'email', 'target' => 'ops@example.com', 'is_enabled' => true]);
        AlertContact::create(['name' => 'Ops hook', 'type' => 'webhook', 'target' => 'https://hooks.example.com/x', 'is_enabled' => true]);
        AlertContact::create(['name' => 'Muted', 'type' => 'email', 'target' => 'nobody@example.com', 'is_enabled' => false]);

        CheckRecorder::record($this->monitor(), CheckResult::down('Unexpected status 500.'));

        Mail::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://hooks.example.com/x'
            && $request['event'] === 'incident.opened');
    }

    public function test_a_disabled_contact_and_another_owners_contact_are_not_notified(): void
    {
        Mail::fake();

        $mine = User::create([
            'name' => 'Owner', 'email' => 'owner@example.com', 'password' => bcrypt('x'), 'role' => 'user',
        ]);
        $theirs = User::create([
            'name' => 'Other', 'email' => 'other@example.com', 'password' => bcrypt('x'), 'role' => 'user',
        ]);

        AlertContact::create(['user_id' => $theirs->id, 'name' => 'Theirs', 'type' => 'email', 'target' => 'theirs@example.com', 'is_enabled' => true]);
        AlertContact::create(['user_id' => $mine->id, 'name' => 'Mine', 'type' => 'email', 'target' => 'mine@example.com', 'is_enabled' => true]);

        CheckRecorder::record($this->monitor(['user_id' => $mine->id]), CheckResult::down('down'));

        Mail::assertSentCount(1);
    }
}
