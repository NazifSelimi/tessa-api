<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RequestStylist extends Model
{
    use HasFactory;

    protected $table = 'request_stylist';
    protected $fillable = [
        'user_id',
        'saloon_name',
        'saloon_city',
        'saloon_address',
        'saloon_phone',
        'message',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

}
