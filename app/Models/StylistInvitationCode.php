<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StylistInvitationCode extends Model
{
    protected $fillable = ['code', 'expires_at', 'used', 'created_by', 'used_by'];

    protected $casts = [
        'used' => 'boolean',
        'expires_at' => 'datetime',
    ];

    // Relationship to the distributor (creator of the code)
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Relationship to the stylist (user of the code)
    public function user()
    {
        return $this->belongsTo(User::class, 'used_by');
    }
}
