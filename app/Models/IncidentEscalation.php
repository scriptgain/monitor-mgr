<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record that a step has already fired for an incident.
 *
 * Without it, "started more than 15 minutes ago" stays true forever and the
 * step fires again every single minute.
 */
class IncidentEscalation extends Model
{
    public $timestamps = false;

    protected $fillable = ['incident_id', 'escalation_step_id', 'sent_at', 'sent_count'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'sent_count' => 'integer'];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(EscalationStep::class, 'escalation_step_id');
    }
}
