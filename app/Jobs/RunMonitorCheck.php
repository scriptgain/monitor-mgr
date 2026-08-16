<?php

namespace App\Jobs;

use App\Checks\CheckRunner;
use App\Models\Monitor;
use App\Services\CheckRecorder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Runs one monitor's check on the queue worker.
 *
 * Not retried: a check that failed to complete is not worth replaying, because
 * the next sweep is at most one interval away and a retry would record a stale
 * result under a current timestamp.
 */
class RunMonitorCheck implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Wall-clock ceiling, so a wedged check cannot hold a worker forever. */
    public int $timeout;

    public function __construct(public int $monitorId, int $checkTimeout = 30)
    {
        $this->timeout = max(10, $checkTimeout) + (int) config('monitor.poll.job_timeout_padding', 30);
    }

    /** One in-flight check per monitor, whatever the queue depth. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("monitor-check-{$this->monitorId}"))->dontRelease()];
    }

    public function handle(): void
    {
        $monitor = Monitor::find($this->monitorId);
        if (! $monitor || $monitor->status === 'paused' || ! CheckRunner::isPolled((string) $monitor->type)) {
            return;
        }

        CheckRecorder::record($monitor, CheckRunner::run($monitor));
    }
}
