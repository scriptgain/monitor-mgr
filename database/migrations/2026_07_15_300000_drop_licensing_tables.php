<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the licensing-management domain tables. This app was cloned from a
 * license-management scaffold; those concepts (products, features, plans,
 * customers, licenses, activations, license servers, locations) do not apply
 * to MonitorMGR, a website + server monitoring platform. Kept: settings,
 * api_tokens, audit_logs, users, and the framework cache/jobs (queue) tables.
 */
return new class extends Migration
{
    private array $tables = [
        'activations', 'plan_feature', 'licenses', 'plans', 'features', 'customers', 'license_servers', 'locations',
    ];

    public function up(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ($this->tables as $t) {
            Schema::dropIfExists($t);
        }
        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // One-way cleanup; the original create migrations were deleted. Restore
        // from the pre-refactor snapshot (monitor-presnapshot.tgz) if needed.
    }
};
