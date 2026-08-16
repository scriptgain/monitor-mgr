<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Threshold rules over host metrics.
 *
 * Until now nothing in the product ever compared a number to a limit: an agent
 * could report 100% disk and the panel would store it, draw it, and tell nobody.
 * A trigger is that missing comparison, and it is what turns the metrics stream
 * into events.
 *
 * A null monitored_host_id means "every host", which is how a fresh install
 * arrives with useful rules and how one edit covers a fleet. A row with a host
 * set overrides the global rule for the same metric on that host.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('triggers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Null means the rule applies to every host the owner can see.
            $table->foreignId('monitored_host_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('metric');                       // cpu_pct | mem_pct | disk_pct | load1 | agent_offline | disk.<mount>.pct ...
            $table->string('operator', 4)->default('>');    // > | >= | < | <=
            $table->float('threshold')->default(0);
            // Hysteresis. The value the metric has to come back past before the
            // incident closes. Null means "the threshold itself", which flaps.
            $table->float('recovery_threshold')->nullable();
            // The condition must hold for this long before it fires. Zero fires on
            // the first breaching sample, which is right for agent_offline and
            // wrong for almost anything else.
            $table->unsignedInteger('for_seconds')->default(300);
            $table->string('severity')->default('average'); // info|warning|average|high|disaster
            $table->boolean('is_enabled')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['is_enabled', 'metric']);
            $table->index('monitored_host_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('triggers');
    }
};
