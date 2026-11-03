<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreCreditTransaction extends Model
{
    // Commission for a referred order, and its reversal when the order is cancelled.
    public const TYPE_REFERRAL = 'referral';
    // Credit paid towards the user's own order, and its refund when that order is cancelled.
    public const TYPE_SPENT = 'spent';
    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = ['user_id', 'order_id', 'type', 'amount', 'note', 'created_by'];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
