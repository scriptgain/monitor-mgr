<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a trigger and a downtime window aim at a group, and gives hosts and
 * monitors free-form tags.
 *
 * Tags are a json array on the row rather than their own table with a pivot.
 * They are only ever read as "does this row carry this label" and written as a
 * whole set from one form field; a join table would buy referential integrity
 * over strings nobody references by id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('triggers', function (Blueprint $table) {
            $table->foreignId('host_group_id')->nullable()->after('monitored_host_id')
                ->constrained()->cascadeOnDelete();
        });

        Schema::table('downtime_windows', function (Blueprint $table) {
            $table->foreignId('host_group_id')->nullable()->after('monitored_host_id')
                ->constrained()->cascadeOnDelete();
        });

        Schema::table('monitored_hosts', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('notes');
        });

        Schema::table('monitors', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('triggers', fn (Blueprint $t) => $t->dropConstrainedForeignId('host_group_id'));
        Schema::table('downtime_windows', fn (Blueprint $t) => $t->dropConstrainedForeignId('host_group_id'));
        Schema::table('monitored_hosts', fn (Blueprint $t) => $t->dropColumn('tags'));
        Schema::table('monitors', fn (Blueprint $t) => $t->dropColumn('tags'));
    }
};
