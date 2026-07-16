<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');                                // http|tcp|ping|keyword|ssl|dns|heartbeat|agent
            $table->string('target');                               // url or host
            $table->unsignedInteger('port')->nullable();
            $table->unsignedInteger('interval_seconds')->default(60);
            $table->unsignedInteger('timeout_seconds')->default(30);
            $table->string('expected')->nullable();                 // e.g. expected status code or keyword
            $table->string('status')->default('paused');            // up|down|paused
            $table->timestamp('last_checked_at')->nullable();
            $table->float('uptime_ratio')->default(100);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitors');
    }
};
