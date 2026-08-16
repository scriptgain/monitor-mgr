<?php

namespace Tests\Feature;

use App\Models\AlertContact;
use App\Models\DowntimeWindow;
use App\Models\EscalationStep;
use App\Models\MonitoredHost;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertingScreensTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Setting::put('setup_complete', '1');

        return User::create([
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('x'), 'role' => 'admin',
        ]);
    }

    public function test_the_alerting_screens_render(): void
    {
        $admin = $this->admin();

        foreach (['/escalations', '/escalations/create', '/downtime', '/downtime/create'] as $path) {
            $this->actingAs($admin)->get($path)->assertOk();
        }
    }

    public function test_it_creates_an_escalation_step(): void
    {
        $admin = $this->admin();
        $contact = AlertContact::create(['name' => 'Ops', 'type' => 'email', 'target' => 'ops@example.com', 'is_enabled' => true]);

        $this->actingAs($admin)->post('/escalations', [
            'name' => 'Page on-call', 'alert_contact_id' => $contact->id,
            'after_minutes' => 15, 'repeat_minutes' => 30, 'min_severity' => 'high', 'is_enabled' => '1',
        ])->assertRedirect('/escalations');

        $step = EscalationStep::first();
        $this->assertSame(15, $step->after_minutes);
        $this->assertSame(30, $step->repeat_minutes);
        $this->assertStringContainsString('repeating every 30m', $step->timing());
    }

    public function test_a_one_off_window_needs_both_ends(): void
    {
        $this->actingAs($this->admin())
            ->post('/downtime', ['name' => 'Half a window', 'kind' => 'once', 'starts_at' => now()->toDateTimeString()])
            ->assertSessionHasErrors('starts_at');
    }

    public function test_a_one_off_window_must_end_after_it_starts(): void
    {
        $this->actingAs($this->admin())->post('/downtime', [
            'name' => 'Backwards', 'kind' => 'once',
            'starts_at' => now()->addHour()->format('Y-m-d\TH:i'),
            'ends_at' => now()->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors('ends_at');
    }

    public function test_a_weekly_window_needs_days_and_times(): void
    {
        $this->actingAs($this->admin())
            ->post('/downtime', ['name' => 'Empty weekly', 'kind' => 'weekly'])
            ->assertSessionHasErrors(['days_of_week', 'start_time']);
    }

    public function test_it_creates_a_weekly_window_and_clears_the_one_off_half(): void
    {
        $this->actingAs($this->admin())->post('/downtime', [
            'name' => 'Sunday patching', 'kind' => 'weekly',
            'days_of_week' => [0], 'start_time' => '02:00', 'end_time' => '04:00',
            'starts_at' => now()->format('Y-m-d\TH:i'),
            'is_enabled' => '1',
        ])->assertRedirect('/downtime');

        $window = DowntimeWindow::first();
        $this->assertSame([0], $window->days_of_week);
        // Switching kinds must not leave the other half armed.
        $this->assertNull($window->starts_at);
        $this->assertStringContainsString('Sun', $window->schedule());
    }

    public function test_the_subject_select_maps_to_exactly_one_column(): void
    {
        $admin = $this->admin();
        $host = MonitoredHost::create(['name' => 'web01']);

        $this->actingAs($admin)->post('/downtime', [
            'name' => 'Host only', 'kind' => 'once',
            'starts_at' => now()->format('Y-m-d\TH:i'),
            'ends_at' => now()->addHour()->format('Y-m-d\TH:i'),
            'subject' => 'host:'.$host->id,
        ])->assertRedirect('/downtime');

        $window = DowntimeWindow::first();
        $this->assertSame($host->id, $window->monitored_host_id);
        $this->assertNull($window->monitor_id);
        $this->assertSame('web01', $window->subjectName());
    }
}
