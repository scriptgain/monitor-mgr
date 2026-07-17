<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MonitoredHost extends Model
{
    use OwnedByUser;

    protected $fillable = ['user_id', 'name', 'hostname', 'os', 'arch', 'cpu_cores', 'agent_version', 'status', 'notes'];

    protected $hidden = ['api_key', 'enrollment_token'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'boot_time' => 'datetime',
            'cpu_cores' => 'integer',
        ];
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(HostMetric::class);
    }

    public function latestMetric()
    {
        return $this->hasOne(HostMetric::class)->latestOfMany('captured_at');
    }

    /**
     * Issue a fresh one-time enrollment token. Only the sha256 hash is stored;
     * the plaintext is returned once for display in the "Add Host" flow.
     */
    public function issueEnrollmentToken(): string
    {
        $plain = Str::random(40);
        $this->forceFill([
            'enrollment_token' => hash('sha256', $plain),
            'api_key' => null,
            'status' => 'pending',
        ])->save();

        return $plain;
    }

    /**
     * Live status derived from the last check-in. A host that has not reported
     * within the offline window reads "offline" even if the stored status still
     * says "online". A host that never enrolled stays "pending".
     */
    public function getEffectiveStatusAttribute(): string
    {
        if (! $this->last_seen_at) {
            return $this->status === 'pending' ? 'pending' : 'offline';
        }
        $window = max(15, (int) config('monitor.offline_after_seconds', 90));
        if ($this->last_seen_at->lt(now()->subSeconds($window))) {
            return 'offline';
        }

        return 'online';
    }

    public function isEnrolled(): bool
    {
        return $this->api_key !== null;
    }
}
