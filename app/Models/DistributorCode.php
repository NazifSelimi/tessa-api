<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps to the stylist_invitation_codes table.
 */
class DistributorCode extends Model
{
    protected $table = 'stylist_invitation_codes';

    protected $fillable = [
        'code',
        'used',
        'expires_at',
        'created_by',
        'used_by',
    ];

    protected $casts = [
        'used' => 'boolean',
        'expires_at' => 'datetime',
    ];

    /**
     * Get the stylist who created this code.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who redeemed this code.
     */
    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }
}
