<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rung of the escalation ladder: after N minutes with nobody acknowledging,
 * tell this contact too.
 *
 * Steps are flat rather than grouped into named policies. A policy object would
 * be the right shape for a large team with different rotas per service, and the
 * wrong shape for the operator this product is for, who has one on-call list and
 * wants their phone to ring if the email goes unread.
 */
class EscalationStep extends Model
{
    use OwnedByUser;

    protected $fillable = [
        'user_id', 'alert_contact_id', 'name', 'after_minutes',
        'min_severity', 'repeat_minutes', 'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'after_minutes' => 'integer',
            'repeat_minutes' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(AlertContact::class, 'alert_contact_id');
    }

    /** Is this incident severe enough for this step? */
    public function coversSeverity(?string $severity): bool
    {
        if ($this->min_severity === null) {
            return true;
        }

        $order = array_keys(Trigger::SEVERITIES);
        $need = array_search($this->min_severity, $order, true);
        $have = array_search($severity ?: 'average', $order, true);

        return $need !== false && $have !== false && $have >= $need;
    }

    public function severityLabel(): string
    {
        return $this->min_severity === null
            ? 'Any severity'
            : (Trigger::SEVERITIES[$this->min_severity] ?? $this->min_severity).' and above';
    }

    public function timing(): string
    {
        $when = $this->after_minutes === 0
            ? 'Immediately'
            : 'After '.$this->after_minutes.' minute'.($this->after_minutes === 1 ? '' : 's');

        return $this->repeat_minutes
            ? $when.', repeating every '.$this->repeat_minutes.'m'
            : $when;
    }
}
