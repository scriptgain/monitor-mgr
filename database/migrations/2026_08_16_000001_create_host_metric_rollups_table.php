<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aggregated host metrics, so history outlives the raw samples.
 *
 * Raw samples are pruned at seven days, and until now that was the end of them:
 * the only stored aggregate anywhere in this schema was one float on `monitors`.
 * A month-long chart was not slow, it was impossible, and every day that passed
 * without this table was a day of history gone permanently.
 *
 * Sizing, measured on the dev database: 39,019 raw samples across two hosts is
 * 17 MB. At a 30 second interval that is 2,880 rows per host per day, against 24
 * hourly rollup rows. Fifty hosts cost roughly 65 MB a day raw and about a
 * megabyte a month rolled up, which is what makes a two year daily series cheap
 * enough to keep without anyone thinking about it.
 *
 * Used and total are stored as sums rather than percentages, for the reason
 * MetricReader already gives about the raw table: a disk that grows changes its
 * percentage without any sample having been wrong. Keeping both halves means a
 * percentage can always be recomputed, and a resize is visible rather than
 * smeared through an average.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('host_metric_rollups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitored_host_id')->constrained()->cascadeOnDelete();
            $table->string('bucket', 8);              // hour | day
            $table->timestamp('bucket_start');
            $table->unsignedInteger('sample_count')->default(0);

            // Averages carry min and max beside them: an hour that averaged 40%
            // CPU while touching 100% is a different hour from one that sat flat
            // at 40, and an average alone cannot tell them apart.
            foreach (['cpu_pct', 'load1', 'load5', 'load15'] as $metric) {
                $table->float($metric.'_avg')->default(0);
                $table->float($metric.'_min')->default(0);
                $table->float($metric.'_max')->default(0);
            }

            foreach (['mem', 'swap', 'disk'] as $metric) {
                $table->unsignedBigInteger($metric.'_used_avg')->default(0);
                $table->unsignedBigInteger($metric.'_used_max')->default(0);
                $table->unsignedBigInteger($metric.'_total_avg')->default(0);
            }

            $table->unsignedBigInteger('net_rx_avg')->default(0);
            $table->unsignedBigInteger('net_rx_max')->default(0);
            $table->unsignedBigInteger('net_tx_avg')->default(0);
            $table->unsignedBigInteger('net_tx_max')->default(0);

            $table->timestamps();

            // The writer is idempotent on this: a re-run overwrites a bucket
            // rather than doubling it, which is what lets it catch up safely
            // after the scheduler has been down.
            $table->unique(['monitored_host_id', 'bucket', 'bucket_start'], 'host_rollup_bucket_unique');
            $table->index(['monitored_host_id', 'bucket', 'bucket_start'], 'host_rollup_range_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_metric_rollups');
    }
};
