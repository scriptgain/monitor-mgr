<?php

namespace App\Console\Commands;

use App\Models\HostMetric;
use App\Models\HostMetricRollup;
use App\Models\MonitoredHost;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Aggregates raw host metrics into hourly and daily buckets.
 *
 * Runs hourly from the scheduler, and is the thing standing between the raw
 * prune and permanent data loss: nothing may delete a raw sample that has not
 * been rolled up first, so this command's high water mark is what the pruner
 * checks before it deletes anything.
 *
 * It catches up rather than only doing "the last hour", so a panel whose
 * scheduler was down for a day fills the gap on its next run instead of leaving
 * a hole that can never be filled once the raw rows age out.
 */
class MonitorRollup extends Command
{
    protected $signature = 'monitor:rollup
        {--host= : Roll up one host by id}
        {--since= : Start from this date instead of the last written bucket, e.g. 2026-08-01}
        {--buckets=hour,day : Which bucket sizes to write}';

    protected $description = 'Aggregate raw host metrics into hourly and daily buckets';

    /**
     * How far back a first run will reach. Without a bound, an install with
     * months of raw samples would try to build every bucket in one pass.
     */
    private const MAX_CATCHUP_DAYS = 400;

    public function handle(): int
    {
        $buckets = array_values(array_intersect(
            array_map('trim', explode(',', (string) $this->option('buckets'))),
            HostMetricRollup::BUCKETS
        ));
        if ($buckets === []) {
            $this->error('No valid bucket sizes given. Use hour, day, or both.');

            return self::FAILURE;
        }

        $hosts = MonitoredHost::query()
            ->when($this->option('host'), fn ($q, $id) => $q->whereKey((int) $id))
            ->get();

        $written = 0;
        foreach ($hosts as $host) {
            foreach ($buckets as $bucket) {
                $written += $this->rollUp($host, $bucket);
            }
        }

        $this->info("Wrote {$written} rollup bucket(s) across {$hosts->count()} host(s).");

        return self::SUCCESS;
    }

    private function rollUp(MonitoredHost $host, string $bucket): int
    {
        $from = $this->startFrom($host, $bucket);
        if ($from === null) {
            return 0;
        }

        // The bucket currently in progress is deliberately left alone. Writing it
        // would record an average over a partial hour, and the next run would
        // have to notice and correct it.
        $until = $this->floor(now(), $bucket);
        $written = 0;

        for ($start = $from; $start->lt($until); $start = $this->advance($start, $bucket)) {
            $end = $this->advance($start->copy(), $bucket);

            $samples = HostMetric::query()
                ->where('monitored_host_id', $host->id)
                ->where('captured_at', '>=', $start)
                ->where('captured_at', '<', $end)
                ->orderBy('captured_at')
                ->get();

            // An empty bucket writes nothing. A host that was switched off for a
            // week should leave a gap in the chart, not a run of zeroes that
            // reads as "idle" when it means "not there".
            if ($samples->isEmpty()) {
                continue;
            }

            HostMetricRollup::updateOrCreate(
                ['monitored_host_id' => $host->id, 'bucket' => $bucket, 'bucket_start' => $start],
                HostMetricRollup::summarize($host->id, $bucket, $start, $samples)
            );
            $written++;
        }

        return $written;
    }

    /** Where this host's catch-up begins: after the last written bucket, or at its oldest sample. */
    private function startFrom(MonitoredHost $host, string $bucket): ?Carbon
    {
        if ($since = $this->option('since')) {
            return $this->floor(Carbon::parse($since), $bucket);
        }

        $last = HostMetricRollup::query()
            ->where('monitored_host_id', $host->id)
            ->where('bucket', $bucket)
            ->max('bucket_start');

        if ($last) {
            return $this->advance($this->floor(Carbon::parse($last), $bucket), $bucket);
        }

        $oldest = HostMetric::query()->where('monitored_host_id', $host->id)->min('captured_at');
        if (! $oldest) {
            return null;
        }

        $floor = now()->subDays(self::MAX_CATCHUP_DAYS);
        $start = Carbon::parse($oldest);

        return $this->floor($start->lt($floor) ? $floor : $start, $bucket);
    }

    private function floor(Carbon $at, string $bucket): Carbon
    {
        return $bucket === 'day' ? $at->copy()->startOfDay() : $at->copy()->startOfHour();
    }

    private function advance(Carbon $at, string $bucket): Carbon
    {
        return $bucket === 'day' ? $at->copy()->addDay() : $at->copy()->addHour();
    }
}
