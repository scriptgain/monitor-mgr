<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Heartbeat monitors are pinged by someone else's cron, so each one needs a
 * URL of its own. The token is the whole credential for that URL, which is why
 * it is random and unique rather than the monitor id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->string('heartbeat_token', 64)->nullable()->unique()->after('expected');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->dropUnique(['heartbeat_token']);
            $table->dropColumn('heartbeat_token');
        });
    }
};
