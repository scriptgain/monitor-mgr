<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Agent-based server monitoring (Beszel-style): a lightweight Go agent installed
// on a host reports resource metrics over outbound HTTPS. `monitored_hosts` holds
// one row per enrolled host; `host_metrics` is a rolling time series of samples.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitored_hosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('hostname')->nullable();
            $table->string('os')->nullable();
            $table->string('arch')->nullable();
            $table->unsignedInteger('cpu_cores')->nullable();
            // Credentials: only sha256 hashes are stored. The enrollment token is
            // one-time (shown once at "Add Host"); the api_key is issued at enroll.
            $table->string('api_key', 64)->nullable()->unique();
            $table->string('enrollment_token', 64)->nullable()->unique();
            $table->string('agent_version')->nullable();
            $table->timestamp('boot_time')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('status')->default('pending'); // pending|online|offline
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('host_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitored_host_id')->constrained()->cascadeOnDelete();
            $table->timestamp('captured_at');
            $table->float('cpu_pct')->default(0);
            $table->unsignedBigInteger('mem_used')->default(0);
            $table->unsignedBigInteger('mem_total')->default(0);
            $table->unsignedBigInteger('swap_used')->default(0);
            $table->unsignedBigInteger('swap_total')->default(0);
            $table->unsignedBigInteger('disk_used')->default(0);
            $table->unsignedBigInteger('disk_total')->default(0);
            $table->float('load1')->default(0);
            $table->float('load5')->default(0);
            $table->float('load15')->default(0);
            $table->unsignedBigInteger('uptime')->default(0);
            $table->unsignedBigInteger('net_rx')->default(0);
            $table->unsignedBigInteger('net_tx')->default(0);
            // Per-disk and per-core detail for the host dashboard.
            $table->json('detail')->nullable();
            $table->index(['monitored_host_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_metrics');
        Schema::dropIfExists('monitored_hosts');
    }
};
