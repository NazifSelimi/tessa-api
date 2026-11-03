<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    use HasFactory;
    protected $table = 'coupons';

    protected $fillable = [
        'code',
        'type',
        'value',
        'quantity',
        'expiration_date',
        'is_active',
        'stylist_id',
        'commission_percentage',
        'first_order_only',
    ];

    protected $casts = [
        'expiration_date' => 'date',
        'value' => 'integer',
        'quantity' => 'integer',
        'is_active' => 'boolean',
        'commission_percentage' => 'decimal:2',
        'first_order_only' => 'boolean',
    ];

    /** A stylist's referral code: unlimited uses, switched off with is_active. */
    public function isReferral(): bool
    {
        return $this->stylist_id !== null;
    }

    /**
     * Check if coupon is still valid (has remaining quantity and not expired).
     */
    public function isValid(): bool
    {
        if ($this->isReferral()) {
            return $this->is_active !== false
                && !($this->expiration_date && $this->expiration_date->isPast());
        }

        if ($this->quantity <= 0) {
            return false;
        }

        if ($this->expiration_date && $this->expiration_date->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Calculate discount for a given subtotal.
     */
    public function calculateDiscount(float $subtotal): float
    {
        if ($this->type === 'percentage') {
            return round(($subtotal * $this->value) / 100, 2);
        }

        // fixed
        return min((float) $this->value, $subtotal);
    }

    public function orders():HasMany
    {
        return $this->hasMany(Order::class, 'coupon_id');
    }

    public function stylist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'stylist_id');
    }

    public function users():BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('used_at');
    }

}
