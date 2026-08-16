<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Escalation: what happens when nobody picks an incident up.
 *
 * Acknowledgement already existed and already stopped nothing, which made it a
 * label rather than a mechanism. A step is "after N minutes unacknowledged, tell
 * this contact as well", so acking is what buys the quiet.
 *
 * incident_escalations is the fired log. Without it a step that fires at minute
 * 15 would fire again at minute 16, and every minute after that, because the
 * condition "started more than 15 minutes ago" stays true forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escalation_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('alert_contact_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Minutes after the incident opened. Zero is legal and means "at the
            // same time as the first alert", which is how you add a second pager
            // to the initial page rather than a later one.
            $table->unsignedInteger('after_minutes')->default(15);
            // Null means every severity. Otherwise the incident must be at least
            // this severe, ranked by Trigger::SEVERITIES order.
            $table->string('min_severity')->nullable();
            // Keep telling them every N minutes until it is acknowledged or
            // resolved. Null means tell them once.
            $table->unsignedInteger('repeat_minutes')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->index(['is_enabled', 'after_minutes']);
        });

        Schema::create('incident_escalations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('escalation_step_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sent_at');
            $table->unsignedInteger('sent_count')->default(1);
            $table->index(['incident_id', 'escalation_step_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_escalations');
        Schema::dropIfExists('escalation_steps');
    }
};
