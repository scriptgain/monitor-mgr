<?php

namespace Tests\Feature;

use App\Models\AlertContact;
use App\Models\DowntimeWindow;
use App\Models\EscalationStep;
use App\Models\Incident;
use App\Models\IncidentEscalation;
use App\Models\Monitor;
use App\Models\Trigger;
use App\Services\EscalationRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EscalationTest extends TestCase
{
    use RefreshDatabase;

    private AlertContact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        Trigger::query()->delete();
        Mail::fake();
        $this->contact = AlertContact::create([
            'name' => 'On-call phone', 'type' => 'email', 'target' => 'oncall@example.com', 'is_enabled' => true,
        ]);
    }

    private function step(array $attributes = []): EscalationStep
    {
        return EscalationStep::create(array_merge([
            'name' => 'Page on-call',
            'alert_contact_id' => $this->contact->id,
            'after_minutes' => 15,
            'is_enabled' => true,
        ], $attributes));
    }

    private function incident(array $attributes = []): Incident
    {
        $monitor = Monitor::create([
            'name' => 'Site', 'type' => 'http', 'target' => 'https://example.com',
            'interval_seconds' => 60, 'timeout_seconds' => 5, 'status' => 'down',
        ]);

        return Incident::create(array_merge([
            'monitor_id' => $monitor->id,
            'started_at' => now()->subMinutes(20),
            'cause' => 'Unexpected status 500.',
            'severity' => 'high',
        ], $attributes));
    }

    public function test_an_old_unacknowledged_incident_escalates(): void
    {
        $this->step();
        $this->incident();

        $this->assertSame(1, EscalationRunner::run());
        Mail::assertSentCount(1);
        $this->assertDatabaseCount('incident_escalations', 1);
    }

    public function test_it_does_not_escalate_before_its_time(): void
    {
        $this->step(['after_minutes' => 60]);
        $this->incident();

        $this->assertSame(0, EscalationRunner::run());
        Mail::assertNothingSent();
    }

    /** The whole contract: acking is what buys the quiet. */
    public function test_acknowledging_stops_the_ladder(): void
    {
        $this->step();
        $this->incident(['acknowledged_at' => now()]);

        $this->assertSame(0, EscalationRunner::run());
        Mail::assertNothingSent();
    }

    public function test_a_resolved_incident_never_escalates(): void
    {
        $this->step();
        $this->incident(['resolved_at' => now(), 'duration_seconds' => 60]);

        $this->assertSame(0, EscalationRunner::run());
    }

    /**
     * "Started more than 15 minutes ago" stays true forever, so without the
     * fired log this step would go again every single minute.
     */
    public function test_a_step_only_fires_once(): void
    {
        $this->step();
        $this->incident();

        EscalationRunner::run();
        $this->assertSame(0, EscalationRunner::run());
        $this->assertSame(0, EscalationRunner::run());
        Mail::assertSentCount(1);
    }

    public function test_a_repeating_step_goes_again_once_its_interval_has_passed(): void
    {
        $this->step(['repeat_minutes' => 10]);
        $this->incident();

        EscalationRunner::run();
        $this->assertSame(0, EscalationRunner::run());

        IncidentEscalation::query()->update(['sent_at' => now()->subMinutes(11)]);
        $this->assertSame(1, EscalationRunner::run());

        Mail::assertSentCount(2);
        $this->assertSame(2, IncidentEscalation::first()->sent_count);
    }

    public function test_severity_gating(): void
    {
        $this->step(['min_severity' => 'disaster']);
        $this->incident(['severity' => 'warning']);

        $this->assertSame(0, EscalationRunner::run());
    }

    public function test_a_severity_at_or_above_the_floor_escalates(): void
    {
        $this->step(['min_severity' => 'average']);
        $this->incident(['severity' => 'high']);

        $this->assertSame(1, EscalationRunner::run());
    }

    public function test_a_disabled_step_and_a_disabled_contact_both_stay_quiet(): void
    {
        $this->step(['is_enabled' => false]);
        $this->incident();
        $this->assertSame(0, EscalationRunner::run());

        $this->step(['name' => 'Live step but dead contact']);
        $this->contact->update(['is_enabled' => false]);
        $this->assertSame(0, EscalationRunner::run());
    }

    public function test_a_downtime_window_holds_the_escalation_too(): void
    {
        $this->step();
        $this->incident();
        DowntimeWindow::create([
            'name' => 'Patching', 'kind' => 'once',
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'is_enabled' => true,
        ]);

        $this->assertSame(0, EscalationRunner::run());
        Mail::assertNothingSent();
        // And nothing is recorded as sent, so it escalates properly once the
        // window ends rather than being skipped forever.
        $this->assertDatabaseCount('incident_escalations', 0);
    }

    public function test_steps_run_in_order_and_each_records_itself(): void
    {
        $second = AlertContact::create(['name' => 'Manager', 'type' => 'email', 'target' => 'boss@example.com', 'is_enabled' => true]);
        $this->step(['name' => 'First', 'after_minutes' => 5]);
        $this->step(['name' => 'Then the manager', 'after_minutes' => 15, 'alert_contact_id' => $second->id]);
        $this->incident();

        $this->assertSame(2, EscalationRunner::run());
        $this->assertDatabaseCount('incident_escalations', 2);
        Mail::assertSentCount(2);
    }

    public function test_the_command_runs_the_ladder(): void
    {
        $this->step();
        $this->incident();

        $this->artisan('monitor:escalate')->assertExitCode(0);

        $this->assertDatabaseCount('incident_escalations', 1);
    }
}
