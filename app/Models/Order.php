<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;
    public const STATUS_PENDING = 0;
    public const STATUS_PAID = 1;
    public const STATUS_SHIPPED = 2;
    public const STATUS_CANCELLED = 3;

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    protected $fillable = [
        'user_id',
        'total',
        'coupon_id',
        'message',
        'discount',
        'status',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'discount' => 'decimal:2',
        'status' => 'integer',
    ];


    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function items():HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function coupon()
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    public function info(): HasOne
    {
        return $this->hasOne(OrderInfo::class);
    }
}
