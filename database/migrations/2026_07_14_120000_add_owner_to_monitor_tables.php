<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Monitors, alert contacts, and status pages become owned resources.
        // Checks/incidents/metrics inherit their owner from the parent monitor.
        foreach (['monitors', 'alert_contacts', 'status_pages'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('user_id')->nullable()->after('id')
                    ->constrained()->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['monitors', 'alert_contacts', 'status_pages'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('user_id');
            });
        }
    }
};
