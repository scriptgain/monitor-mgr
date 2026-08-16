<?php

namespace Tests\Feature;

use App\Models\AlertContact;
use App\Models\DowntimeWindow;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitoredHost;
use App\Models\Trigger;
use App\Services\AlertDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DowntimeWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Trigger::query()->delete();
        AlertContact::create(['name' => 'Ops', 'type' => 'email', 'target' => 'ops@example.com', 'is_enabled' => true]);
    }

    private function monitor(): Monitor
    {
        return Monitor::create([
            'name' => 'Site', 'type' => 'http', 'target' => 'https://example.com',
            'interval_seconds' => 60, 'timeout_seconds' => 5, 'status' => 'up',
        ]);
    }

    private function incident(?Monitor $monitor = null): Incident
    {
        $monitor ??= $this->monitor();

        return Incident::create([
            'monitor_id' => $monitor->id, 'started_at' => now(), 'cause' => 'boom', 'severity' => 'high',
        ]);
    }

    public function test_a_one_off_window_covers_the_time_between_its_ends(): void
    {
        $w = DowntimeWindow::create([
            'name' => 'Patching', 'kind' => 'once',
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'is_enabled' => true,
        ]);

        $this->assertTrue($w->coversTime());
        $this->assertFalse($w->coversTime(now()->addHours(2)));
        $this->assertFalse($w->coversTime(now()->subHours(2)));
    }

    public function test_a_disabled_window_covers_nothing(): void
    {
        $w = DowntimeWindow::create([
            'name' => 'Off', 'kind' => 'once',
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'is_enabled' => false,
        ]);

        $this->assertFalse($w->coversTime());
    }

    public function test_a_weekly_window_matches_the_day_and_the_time(): void
    {
        // A Wednesday at 03:00.
        $at = Carbon::parse('2026-08-12 03:00:00');
        $w = DowntimeWindow::create([
            'name' => 'Nightly', 'kind' => 'weekly', 'days_of_week' => [3],
            'start_time' => '02:00', 'end_time' => '04:00', 'is_enabled' => true,
        ]);

        $this->assertTrue($w->coversTime($at));
        $this->assertFalse($w->coversTime($at->copy()->setTime(5, 0)));   // after the window
        $this->assertFalse($w->coversTime($at->copy()->addDay()));        // Thursday
    }

    /** Most maintenance happens over midnight, so the wrap has to work. */
    public function test_a_weekly_window_can_run_over_midnight(): void
    {
        $w = DowntimeWindow::create([
            'name' => 'Late', 'kind' => 'weekly', 'days_of_week' => [6],
            'start_time' => '23:00', 'end_time' => '01:00', 'is_enabled' => true,
        ]);

        $saturday = Carbon::parse('2026-08-15 23:30:00');
        $this->assertSame(6, $saturday->dayOfWeek);
        $this->assertTrue($w->coversTime($saturday));
        $this->assertTrue($w->coversTime($saturday->copy()->setTime(0, 30)));
        $this->assertFalse($w->coversTime($saturday->copy()->setTime(12, 0)));
    }

    public function test_a_window_with_no_subject_covers_everything(): void
    {
        $w = DowntimeWindow::create(['name' => 'All', 'kind' => 'once',
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'is_enabled' => true]);

        $this->assertTrue($w->coversSubject($this->incident()));
    }

    public function test_a_window_scoped_to_one_monitor_ignores_another(): void
    {
        $mine = $this->monitor();
        $other = $this->monitor();
        $w = DowntimeWindow::create(['name' => 'Just mine', 'kind' => 'once', 'monitor_id' => $mine->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'is_enabled' => true]);

        $this->assertTrue($w->coversSubject($this->incident($mine)));
        $this->assertFalse($w->coversSubject($this->incident($other)));
    }

    public function test_a_host_window_does_not_catch_a_monitor_incident(): void
    {
        $host = MonitoredHost::create(['name' => 'web01']);
        $w = DowntimeWindow::create(['name' => 'Host only', 'kind' => 'once', 'monitored_host_id' => $host->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'is_enabled' => true]);

        $this->assertFalse($w->coversSubject($this->incident()));
    }

    public function test_an_active_window_holds_the_open_notification(): void
    {
        Mail::fake();
        DowntimeWindow::create(['name' => 'Patching', 'kind' => 'once',
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'is_enabled' => true]);

        $sent = AlertDispatcher::incidentOpened($this->incident());

        $this->assertSame(0, $sent);
        Mail::assertNothingSent();
    }

    /** "It is fixed" is never noise, so a recovery goes out regardless. */
    public function test_a_resolution_still_goes_out_during_a_window(): void
    {
        Mail::fake();
        DowntimeWindow::create(['name' => 'Patching', 'kind' => 'once',
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'is_enabled' => true]);

        $incident = $this->incident();
        $incident->update(['resolved_at' => now(), 'duration_seconds' => 30]);

        $this->assertGreaterThan(0, AlertDispatcher::incidentResolved($incident));
        Mail::assertSentCount(1);
    }

    public function test_an_expired_window_holds_nothing(): void
    {
        Mail::fake();
        DowntimeWindow::create(['name' => 'Yesterday', 'kind' => 'once',
            'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay(), 'is_enabled' => true]);

        $this->assertGreaterThan(0, AlertDispatcher::incidentOpened($this->incident()));
        Mail::assertSentCount(1);
    }
}
