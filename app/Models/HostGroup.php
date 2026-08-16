<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named set of hosts, used as a target for triggers and downtime windows.
 *
 * Membership is many to many because the useful groupings overlap: "production"
 * and "database" are both true of the same box, and forcing a choice between
 * them is what makes people give up on groups.
 */
class HostGroup extends Model
{
    use OwnedByUser;

    /** Tailwind-ish names the badge component already understands. */
    public const COLORS = [
        'neutral' => 'Grey', 'brand' => 'Blue', 'success' => 'Green',
        'warn' => 'Amber', 'danger' => 'Red',
    ];

    protected $fillable = ['user_id', 'name', 'color', 'description'];

    public function hosts(): BelongsToMany
    {
        return $this->belongsToMany(MonitoredHost::class, 'host_group_monitored_host');
    }

    public function triggers(): HasMany
    {
        return $this->hasMany(Trigger::class);
    }

    public function downtimeWindows(): HasMany
    {
        return $this->hasMany(DowntimeWindow::class);
    }

    public function badgeColor(): string
    {
        return array_key_exists((string) $this->color, self::COLORS) ? (string) $this->color : 'neutral';
    }
}
