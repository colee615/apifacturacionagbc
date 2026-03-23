<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class IntegrationToken extends Model
{
    use HasFactory;

    public const STATUS_INACTIVE = 0;
    public const STATUS_ACTIVE = 1;

    protected $fillable = [
        'name',
        'description',
        'token_prefix',
        'token_hash',
        'token_value',
        'estado',
        'last_used_at',
        'expires_at',
        'created_by',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'token_value' => 'encrypted',
    ];

    protected $hidden = [
        'token_hash',
        'token_value',
    ];

    public function creator()
    {
        return $this->belongsTo(Usuario::class, 'created_by');
    }

    public function isActive(): bool
    {
        if ((int) $this->estado !== self::STATUS_ACTIVE) {
            return false;
        }

        if ($this->expires_at instanceof Carbon && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function markAsUsed(): void
    {
        $this->forceFill([
            'last_used_at' => now(),
        ])->save();
    }
}
