<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->float('cpu_pct')->nullable();
            $table->float('mem_pct')->nullable();
            $table->float('disk_pct')->nullable();
            $table->float('load1')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['monitor_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metrics');
    }
};
