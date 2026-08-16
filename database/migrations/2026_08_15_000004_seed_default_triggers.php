<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ships the rules a new install would otherwise have to invent.
 *
 * This is the part Zabbix does badly: a fresh install there monitors nothing
 * until somebody links a template. Here every enrolled host is covered the
 * moment it reports, and these are ordinary rows an operator can edit or switch
 * off. They are global (no host set), so they also cover hosts enrolled before
 * this migration ran.
 *
 * Guarded on being empty so an install that already has triggers, or a re-run,
 * does not get duplicates.
 */
return new class extends Migration
{
    private array $defaults = [
        ['name' => 'CPU above 90%', 'metric' => 'cpu_pct', 'threshold' => 90, 'recovery_threshold' => 80, 'for_seconds' => 300, 'severity' => 'warning'],
        ['name' => 'Memory above 90%', 'metric' => 'mem_pct', 'threshold' => 90, 'recovery_threshold' => 80, 'for_seconds' => 300, 'severity' => 'warning'],
        ['name' => 'Disk above 90%', 'metric' => 'disk_pct', 'threshold' => 90, 'recovery_threshold' => 85, 'for_seconds' => 600, 'severity' => 'high'],
        ['name' => 'Swap above 50%', 'metric' => 'swap_pct', 'threshold' => 50, 'recovery_threshold' => 30, 'for_seconds' => 900, 'severity' => 'info'],
        // Zero for_seconds: the offline window is already the delay, and adding a
        // second one on top would mean a host is gone for ten minutes in silence.
        ['name' => 'Agent stopped reporting', 'metric' => 'agent_offline', 'threshold' => 1, 'recovery_threshold' => null, 'for_seconds' => 0, 'severity' => 'high'],
    ];

    public function up(): void
    {
        if (DB::table('triggers')->exists()) {
            return;
        }

        $now = now();
        DB::table('triggers')->insert(array_map(fn ($d) => $d + [
            'user_id' => null,
            'monitored_host_id' => null,
            'operator' => '>',
            'is_enabled' => true,
            'notes' => 'Shipped with MonitorMGR. Edit or disable it like any other rule.',
            'created_at' => $now,
            'updated_at' => $now,
        ], $this->defaults));
    }

    public function down(): void
    {
        DB::table('triggers')
            ->whereNull('monitored_host_id')
            ->whereIn('metric', array_column($this->defaults, 'metric'))
            ->delete();
    }
};
