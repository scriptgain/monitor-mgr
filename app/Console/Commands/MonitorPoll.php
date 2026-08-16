<?php

namespace App\Console\Commands;

use App\Checks\CheckResult;
use App\Checks\CheckRunner;
use App\Jobs\RunMonitorCheck;
use App\Models\Monitor;
use App\Services\CheckRecorder;
use App\Services\TriggerEvaluator;
use Illuminate\Console\Command;

/**
 * The scheduler's one-minute sweep: queue a check for every monitor whose
 * interval has elapsed, then mark any heartbeat monitor that has gone quiet.
 *
 * This is what makes the product monitor anything. Without `schedule:run` in
 * cron and a running `queue:work`, no check ever executes.
 */
class MonitorPoll extends Command
{
    protected $signature = 'monitor:poll
        {--sync : Run the checks inline instead of queueing them}
        {--monitor= : Poll one monitor by id, ignoring its interval}
        {--force : Ignore intervals and poll everything that is due or not}';

    protected $description = 'Run due monitor checks and expire missed heartbeats';

    public function handle(): int
    {
        $queued = $this->pollDue();
        $missed = $this->sweepHeartbeats();
        // Silence is the one condition agent ingest can never report on itself.
        $offline = TriggerEvaluator::sweepOffline();

        $this->info("Polled {$queued} monitor(s); {$missed} heartbeat(s) missed; {$offline} host(s) went offline.");

        return self::SUCCESS;
    }

    /** Queue (or run) every monitor whose interval has elapsed. */
    private function pollDue(): int
    {
        $query = Monitor::query()
            ->whereIn('type', CheckRunner::POLLED)
            ->where('status', '!=', 'paused');

        if ($id = $this->option('monitor')) {
            $query->whereKey((int) $id);
        }

        $cap = max(1, (int) config('monitor.poll.max_per_sweep', 500));
        $all = $query->get();

        // Due-ness is decided in PHP rather than SQL: the comparison needs
        // interval_seconds against a timestamp, and the date arithmetic for
        // that is not portable between MySQL and the SQLite used in tests.
        $monitors = ($this->option('monitor') || $this->option('force'))
            ? $all
            : $all->filter(fn (Monitor $m) => $this->isDue($m));

        if ($monitors->count() > $cap) {
            $this->warn("More than {$cap} monitors are due; the rest wait for the next sweep.");
            $monitors = $monitors->take($cap);
        }

        foreach ($monitors as $monitor) {
            if ($this->option('sync')) {
                CheckRecorder::record($monitor, CheckRunner::run($monitor));

                continue;
            }
            RunMonitorCheck::dispatch($monitor->id, (int) $monitor->timeout_seconds);
        }

        return $monitors->count();
    }

    private function isDue(Monitor $monitor): bool
    {
        if (! $monitor->last_checked_at) {
            return true;
        }

        // A few seconds of slack, because the sweep fires on a whole minute and
        // a 60s monitor checked at :00.4 would otherwise slip to the next one.
        $interval = max(10, (int) $monitor->interval_seconds) - 5;

        return $monitor->last_checked_at->lte(now()->subSeconds($interval));
    }

    /**
     * A heartbeat monitor is fed by an outside job pinging its URL. Silence past
     * the deadline is the failure, so this is where it becomes a check result.
     */
    private function sweepHeartbeats(): int
    {
        $missed = 0;

        $monitors = Monitor::query()
            ->where('type', 'heartbeat')
            ->where('status', '!=', 'paused')
            ->get();

        foreach ($monitors as $monitor) {
            // A heartbeat that has never been pinged is waiting to be wired up,
            // not failing. Alerting on it would page whoever created it.
            if (! $monitor->last_checked_at) {
                continue;
            }
            if ($monitor->last_checked_at->gt(now()->subSeconds($monitor->heartbeatDeadline()))) {
                continue;
            }
            if ($monitor->status === 'down') {
                continue; // Already recorded; do not restate it every minute.
            }

            $late = (int) $monitor->last_checked_at->diffInSeconds(now());
            CheckRecorder::record($monitor, CheckResult::down("No heartbeat for {$late}s."));
            $missed++;
        }

        return $missed;
    }
}
