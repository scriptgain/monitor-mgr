<?php

namespace App\Console\Commands;

use App\Http\Controllers\MaintenanceController;
use App\Models\AuditLog;
use Illuminate\Console\Command;

class MonitorMaintenance extends Command
{
    protected $signature = 'monitor:maintenance {--force : Ignore the configured maintenance window}';

    protected $description = 'Prune old check/metric telemetry and resolved incidents, and prune old audit rows.';

    public function handle(): int
    {
        if (! $this->option('force') && ! MaintenanceController::allowedNow()) {
            $this->info('Outside the maintenance window; skipping. Use --force to override.');

            return self::SUCCESS;
        }

        $c = MaintenanceController::runSweep();

        $this->info("Maintenance: {$c['telemetry_pruned']} telemetry row(s), {$c['incidents_pruned']} incident(s), {$c['audit_pruned']} audit row(s) pruned.");
        AuditLog::record('maintenance', "Scheduled maintenance: {$c['telemetry_pruned']} telemetry rows, {$c['incidents_pruned']} incidents, {$c['audit_pruned']} audit rows pruned");

        return self::SUCCESS;
    }
}
