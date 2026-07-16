<?php

namespace App\Models;

use App\Models\Concerns\OwnedByUser;
use Illuminate\Database\Eloquent\Model;

class AlertContact extends Model
{
    use OwnedByUser;

    public const TYPES = [
        'email' => 'Email',
        'webhook' => 'Webhook',
        'sms' => 'SMS',
        'slack' => 'Slack',
    ];

    protected $fillable = ['user_id', 'name', 'type', 'target', 'is_enabled'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }
}
