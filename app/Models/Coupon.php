<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
    ];

    protected $casts = [
        'expiration_date' => 'date',
        'value' => 'integer',
        'quantity' => 'integer',
    ];

    /**
     * Check if coupon is still valid (has remaining quantity and not expired).
     */
    public function isValid(): bool
    {
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

    public function users():BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('used_at');
    }

}
