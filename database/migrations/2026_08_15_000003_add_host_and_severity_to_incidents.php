<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An incident could only ever belong to a monitor, which is why a host at 100%
 * disk had nowhere to become one. It now belongs to a monitor OR a host, and
 * carries the severity of whatever opened it.
 *
 * monitor_id becomes nullable rather than being replaced by a polymorphic pair:
 * every existing row, index, and `whereNull('resolved_at')` query keeps working,
 * and the two columns are mutually exclusive by construction rather than by a
 * type string that can disagree with the id beside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->foreignId('monitored_host_id')->nullable()->after('monitor_id')
                ->constrained()->cascadeOnDelete();
            $table->foreignId('trigger_id')->nullable()->after('monitored_host_id')
                ->constrained()->nullOnDelete();
            $table->string('severity')->default('average')->after('cause');
            $table->index(['monitored_host_id', 'started_at']);
        });

        // Separate statement: SQLite rebuilds the table for a column change, and
        // doing that in the same closure as the adds above loses them.
        Schema::table('incidents', function (Blueprint $table) {
            $table->unsignedBigInteger('monitor_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex(['monitored_host_id', 'started_at']);
            $table->dropConstrainedForeignId('monitored_host_id');
            $table->dropConstrainedForeignId('trigger_id');
            $table->dropColumn('severity');
        });
    }
};
