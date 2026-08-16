<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Host groups, and the reason they exist.
 *
 * A trigger or a downtime window could target one host or every host, and
 * nothing in between. On a fleet of any size that is the difference between
 * "web servers run hot, raise their CPU threshold" being one rule and being
 * fourteen copies of the same rule that drift apart.
 *
 * A host may belong to several groups, because the useful groupings overlap:
 * "production" and "database" are both true of the same box.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('host_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('color', 20)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('host_group_monitored_host', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitored_host_id')->constrained()->cascadeOnDelete();
            $table->unique(['host_group_id', 'monitored_host_id'], 'host_group_member_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_group_monitored_host');
        Schema::dropIfExists('host_groups');
    }
};
