<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planned downtime, which suppresses alerts without suppressing collection.
 *
 * Not to be confused with Settings > Maintenance, which is housekeeping: it
 * decides when the pruning sweep may run and has never had anything to do with
 * alerting. This is the other thing operators mean by "maintenance window", and
 * its absence is why a Sunday reboot pages the whole on-call list.
 *
 * Collection deliberately continues through a window. Suppressing the checks as
 * well would leave a hole in the history exactly where someone was changing
 * things, which is the last place you want one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('downtime_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            // Both null means everything. Only one is ever set.
            $table->foreignId('monitor_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('monitored_host_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind')->default('once');  // once | weekly
            // once
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            // weekly: 0 = Sunday, matching Carbon::dayOfWeek
            $table->json('days_of_week')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['is_enabled', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('downtime_windows');
    }
};
