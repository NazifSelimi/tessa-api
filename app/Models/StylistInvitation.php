<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StylistInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_reference',
        'display_name',
        'email',
        'phone',
        'address',
        'city',
        'postal_code',
        'business_name',
        'business_address',
        'business_city',
        'business_phone',
        'token_hash',
        'expires_at',
        'activated_at',
        'activated_user_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'activated_at' => 'datetime',
    ];

    public function activatedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_user_id');
    }

    public function isAvailable(): bool
    {
        return $this->activated_at === null && $this->expires_at->isFuture();
    }
}
