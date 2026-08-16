<?php

namespace Tests\Feature;

use App\Http\Controllers\MaintenanceController;
use App\Models\Check;
use App\Models\DowntimeWindow;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitoredHost;
use App\Models\Setting;
use App\Models\StatusPage;
use App\Models\Trigger;
use App\Models\User;
use App\Services\AvailabilityCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Trigger::query()->delete();
    }

    private function monitor(array $attributes = []): Monitor
    {
        $monitor = Monitor::create(array_merge([
            'name' => 'Site', 'type' => 'http', 'target' => 'https://example.com',
            'interval_seconds' => 60, 'timeout_seconds' => 5, 'status' => 'up',
        ], $attributes));
        // Old enough that a 30 day window counts as complete data.
        $monitor->forceFill(['created_at' => now()->subYear()])->save();

        return $monitor->fresh();
    }

    private function outage(Monitor $monitor, Carbon $from, ?Carbon $to): Incident
    {
        return Incident::create([
            'monitor_id' => $monitor->id,
            'started_at' => $from,
            'resolved_at' => $to,
            'duration_seconds' => $to ? (int) $from->diffInSeconds($to) : null,
            'cause' => 'down',
            'severity' => 'high',
        ]);
    }

    public function test_no_incidents_is_a_hundred_percent(): void
    {
        $r = AvailabilityCalculator::for($this->monitor(), now()->subDay(), now());

        $this->assertSame(100.0, $r['uptime']);
        $this->assertSame(0, $r['downtime_seconds']);
        $this->assertSame(0, $r['incidents']);
    }

    /** One hour down in a 24 hour window is 23/24, and the arithmetic has to say so. */
    public function test_one_hour_of_downtime_in_a_day(): void
    {
        $monitor = $this->monitor();
        $this->outage($monitor, now()->subHours(5), now()->subHours(4));

        $r = AvailabilityCalculator::for($monitor, now()->subDay(), now());

        $this->assertSame(3600, $r['downtime_seconds']);
        $this->assertSame(round(23 / 24 * 100, 4), $r['uptime']);
    }

    /** An outage that began before the window contributes only the part inside it. */
    public function test_an_incident_starting_before_the_window_is_clamped(): void
    {
        $monitor = $this->monitor();
        $this->outage($monitor, now()->subHours(30), now()->subHours(23));

        $r = AvailabilityCalculator::for($monitor, now()->subDay(), now());

        // Window opens 24h ago, outage ends 23h ago: one hour counted, not seven.
        $this->assertSame(3600, $r['downtime_seconds']);
    }

    /** An open incident runs to the end of the window rather than to null. */
    public function test_an_open_incident_is_clamped_to_now(): void
    {
        $monitor = $this->monitor();
        $this->outage($monitor, now()->subHours(2), null);

        $r = AvailabilityCalculator::for($monitor, now()->subDay(), now());

        $this->assertSame(7200, $r['downtime_seconds']);
    }

    public function test_an_incident_entirely_outside_the_window_is_ignored(): void
    {
        $monitor = $this->monitor();
        $this->outage($monitor, now()->subDays(10), now()->subDays(9));

        $r = AvailabilityCalculator::for($monitor, now()->subDay(), now());

        $this->assertSame(100.0, $r['uptime']);
        $this->assertSame(0, $r['incidents']);
    }

    public function test_multiple_outages_add_up(): void
    {
        $monitor = $this->monitor();
        $this->outage($monitor, now()->subHours(10), now()->subHours(9));
        $this->outage($monitor, now()->subHours(5), now()->subHours(4));

        $r = AvailabilityCalculator::for($monitor, now()->subDay(), now());

        $this->assertSame(7200, $r['downtime_seconds']);
        $this->assertSame(2, $r['incidents']);
    }

    // ---- planned downtime ----

    /** The headline behavior: a window you scheduled does not count against you. */
    public function test_planned_downtime_is_excluded_not_counted(): void
    {
        $monitor = $this->monitor();
        $this->outage($monitor, now()->subHours(5), now()->subHours(4));
        DowntimeWindow::create([
            'name' => 'Patching', 'kind' => 'once', 'monitor_id' => $monitor->id,
            'starts_at' => now()->subHours(6), 'ends_at' => now()->subHours(3), 'is_enabled' => true,
        ]);

        $r = AvailabilityCalculator::for($monitor, now()->subDay(), now());

        $this->assertSame(0, $r['downtime_seconds'], 'the outage was planned');
        $this->assertSame(3600, $r['excluded_seconds']);
        // The hour is removed from the denominator rather than counted as up.
        $this->assertSame(86400 - 3600, $r['counted_seconds']);
        $this->assertSame(100.0, $r['uptime']);
    }

    public function test_half_a_planned_window_moves_the_number_by_exactly_half(): void
    {
        $monitor = $this->monitor();
        $this->outage($monitor, now()->subHours(6), now()->subHours(4));  // two hours down
        DowntimeWindow::create([
            'name' => 'Half', 'kind' => 'once', 'monitor_id' => $monitor->id,
            'starts_at' => now()->subHours(6), 'ends_at' => now()->subHours(5), 'is_enabled' => true,
        ]);

        $r = AvailabilityCalculator::for($monitor, now()->subDay(), now());

        $this->assertSame(3600, $r['downtime_seconds'], 'one of the two hours was planned');
        $this->assertSame(3600, $r['excluded_seconds']);
    }

    public function test_a_disabled_window_excuses_nothing(): void
    {
        $monitor = $this->monitor();
        $this->outage($monitor, now()->subHours(5), now()->subHours(4));
        DowntimeWindow::create([
            'name' => 'Off', 'kind' => 'once', 'monitor_id' => $monitor->id,
            'starts_at' => now()->subHours(6), 'ends_at' => now()->subHours(3), 'is_enabled' => false,
        ]);

        $r = AvailabilityCalculator::for($monitor, now()->subDay(), now());

        $this->assertSame(3600, $r['downtime_seconds']);
    }

    /** Two windows covering the same outage must not excuse it twice. */
    public function test_overlapping_windows_cannot_push_uptime_above_a_hundred(): void
    {
        $monitor = $this->monitor();
        $this->outage($monitor, now()->subHours(5), now()->subHours(4));
        foreach (['A', 'B'] as $name) {
            DowntimeWindow::create([
                'name' => $name, 'kind' => 'once', 'monitor_id' => $monitor->id,
                'starts_at' => now()->subHours(6), 'ends_at' => now()->subHours(3), 'is_enabled' => true,
            ]);
        }

        $r = AvailabilityCalculator::for($monitor, now()->subDay(), now());

        $this->assertSame(3600, $r['excluded_seconds'], 'excluded twice');
        $this->assertLessThanOrEqual(100.0, $r['uptime']);
    }

    // ---- the overlap maths on recurring windows ----

    public function test_a_one_off_window_overlap(): void
    {
        $w = DowntimeWindow::create([
            'name' => 'Once', 'kind' => 'once',
            'starts_at' => now()->subHours(5), 'ends_at' => now()->subHours(3), 'is_enabled' => true,
        ]);

        $this->assertSame(7200, $w->overlapSeconds(now()->subDay(), now()));
        // Only the overlapping hour, not the whole window.
        $this->assertSame(3600, $w->overlapSeconds(now()->subHours(4), now()));
        $this->assertSame(0, $w->overlapSeconds(now()->subHours(2), now()));
    }

    public function test_a_weekly_window_counts_every_occurrence_in_the_range(): void
    {
        $w = DowntimeWindow::create([
            'name' => 'Nightly', 'kind' => 'weekly',
            'days_of_week' => [0, 1, 2, 3, 4, 5, 6],
            'start_time' => '02:00', 'end_time' => '04:00', 'is_enabled' => true,
        ]);

        // Seven days, two hours a night, every night.
        $from = Carbon::parse('2026-08-01 00:00:00');
        $to = Carbon::parse('2026-08-08 00:00:00');

        $this->assertSame(7 * 7200, $w->overlapSeconds($from, $to));
    }

    public function test_a_weekly_window_only_counts_its_days(): void
    {
        $w = DowntimeWindow::create([
            'name' => 'Sundays', 'kind' => 'weekly', 'days_of_week' => [0],
            'start_time' => '02:00', 'end_time' => '04:00', 'is_enabled' => true,
        ]);

        $from = Carbon::parse('2026-08-01 00:00:00');
        $to = Carbon::parse('2026-08-08 00:00:00');

        $this->assertSame(7200, $w->overlapSeconds($from, $to), 'exactly one Sunday in the range');
    }

    /** The wrap is where a closed form would go wrong, so it gets its own test. */
    public function test_a_weekly_window_over_midnight_counts_both_halves(): void
    {
        $w = DowntimeWindow::create([
            'name' => 'Late', 'kind' => 'weekly', 'days_of_week' => [5],
            'start_time' => '23:00', 'end_time' => '01:00', 'is_enabled' => true,
        ]);

        // Friday 2026-08-07 23:00 to Saturday 01:00.
        $friday = Carbon::parse('2026-08-07');
        $this->assertSame(5, $friday->dayOfWeek);

        $this->assertSame(7200, $w->overlapSeconds(
            Carbon::parse('2026-08-07 00:00:00'), Carbon::parse('2026-08-09 00:00:00')
        ));
        // A range that begins after midnight still catches the tail.
        $this->assertSame(3600, $w->overlapSeconds(
            Carbon::parse('2026-08-08 00:00:00'), Carbon::parse('2026-08-08 12:00:00')
        ));
    }

    // ---- hosts, daily series, and reporting ----

    public function test_it_works_for_hosts_too(): void
    {
        $host = MonitoredHost::create(['name' => 'web01']);
        $host->forceFill(['created_at' => now()->subYear()])->save();
        Incident::create([
            'monitored_host_id' => $host->id, 'started_at' => now()->subHours(3),
            'resolved_at' => now()->subHours(2), 'severity' => 'high',
        ]);

        $r = AvailabilityCalculator::for($host->fresh(), now()->subDay(), now());

        $this->assertSame(3600, $r['downtime_seconds']);
    }

    /** A subject enrolled yesterday must not report a confident 100% over 90 days. */
    public function test_a_young_subject_is_flagged_as_incomplete(): void
    {
        $monitor = Monitor::create([
            'name' => 'New', 'type' => 'http', 'target' => 'https://new.example.com',
            'interval_seconds' => 60, 'timeout_seconds' => 5, 'status' => 'up',
        ]);

        $r = AvailabilityCalculator::for($monitor, now()->subDays(90), now());

        $this->assertFalse($r['has_data']);
    }

    public function test_the_daily_series_returns_one_entry_per_day(): void
    {
        $monitor = $this->monitor();
        $this->outage($monitor, now()->subDays(2)->startOfDay()->addHours(1), now()->subDays(2)->startOfDay()->addHours(2));

        $daily = AvailabilityCalculator::daily($monitor, 7);

        $this->assertCount(7, $daily);
        $bad = collect($daily)->firstWhere('date', now()->subDays(2)->toDateString());
        $this->assertSame(3600, $bad['downtime_seconds']);
        $this->assertSame(round(23 / 24 * 100, 4), $bad['uptime']);
    }

    public function test_the_reports_page_and_csv(): void
    {
        Setting::put('setup_complete', '1');
        $admin = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => bcrypt('x'), 'role' => 'admin']);
        $monitor = $this->monitor(['name' => 'Reported Site']);
        $this->outage($monitor, now()->subHours(3), now()->subHours(2));

        $this->actingAs($admin)->get('/reports?period=30d')
            ->assertOk()->assertSee('Reported Site')->assertSee('Availability');

        $csv = $this->actingAs($admin)->get('/reports/export?period=30d');
        $csv->assertOk()->assertHeader('content-type', 'text/csv; charset=utf-8');
        $body = $csv->streamedContent();
        $this->assertStringContainsString('Reported Site', $body);
        $this->assertStringContainsString('Uptime %', $body);
    }

    public function test_the_public_status_page_labels_its_period(): void
    {
        Setting::put('setup_complete', '1');
        $monitor = $this->monitor(['name' => 'Public Site']);
        $page = StatusPage::create(['name' => 'Status', 'slug' => 'status', 'is_public' => true]);
        $page->monitors()->attach($monitor->id);

        $this->get('/status/status')
            ->assertOk()
            ->assertSee('Public Site')
            ->assertSee('over the last 30 days');
    }

    /** Pruning checks changes the window uptime_ratio is derived from. */
    public function test_pruning_checks_recomputes_the_stored_ratio(): void
    {
        $monitor = $this->monitor();
        foreach (range(1, 10) as $i) {
            Check::create([
                'monitor_id' => $monitor->id,
                'checked_at' => now()->subDays(60),
                'status' => $i <= 5 ? 'down' : 'up',
            ]);
        }
        $monitor->forceFill(['uptime_ratio' => 50])->save();

        Setting::put('telemetry_days', '30');
        MaintenanceController::runSweep();

        // Every check was pruned, so the ratio resets rather than staying at the
        // 50% it froze at.
        $this->assertSame(0, Check::count());
        $this->assertSame(100.0, (float) $monitor->fresh()->uptime_ratio);
    }
}
