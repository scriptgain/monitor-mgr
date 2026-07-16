<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class StatusPage extends Model
{
    use OwnedByUser;

    protected $fillable = ['user_id', 'name', 'slug', 'is_public', 'description'];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    /** Pivot table name pinned to `monitor_status_page`. */
    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class, 'monitor_status_page');
    }
}
