<?php

namespace App\Models\Concerns;

use App\Models\User;

/** Ownership inherited from the parent monitor (checks, incidents, metrics). */
trait OwnedViaMonitor
{
    public function scopeVisibleTo($query, ?User $user)
    {
        if ($user && ! $user->isAdmin()) {
            $query->whereHas('monitor', fn ($m) => $m->where('user_id', $user->id));
        }

        return $query;
    }

    public function isVisibleTo(?User $user): bool
    {
        return $user && ($user->isAdmin() || ($this->monitor && $this->monitor->user_id === $user->id));
    }
}
