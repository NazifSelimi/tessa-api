<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StylistProfile extends Model
{
    use HasFactory;

    protected $table = 'stylist_profiles';
    protected $fillable = [
        'user_id',
        'saloon_name',
        'saloon_city',
        'saloon_address',
        'saloon_phone',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
