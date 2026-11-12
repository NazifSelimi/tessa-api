<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The admin's private notes and tags about one customer (account or guest buyer). */
class CustomerNote extends Model
{
    protected $fillable = ['customer_key', 'user_id', 'notes', 'tags', 'updated_by'];

    protected $casts = [
        'tags' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
