<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public const ROLE_USER = 0;
    public const ROLE_STYLIST = 2;
    public const ROLE_ADMIN = 1;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'address',
        'city',
        'phone',
        'postcode',
        'request_submitted',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'request_submitted' => 'boolean',
        'role' => 'integer',
    ];

    public function isStylist(): bool
    {
        return (int) $this->role === self::ROLE_STYLIST;
    }

    public function isAdmin(): bool
    {
        return (int) $this->role === self::ROLE_ADMIN;
    }
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(StylistProfile::class);
    }

    public function request(): HasOne
    {
        return $this->hasOne(RequestStylist::class, 'user_id');
    }

    public function coupons(): BelongsToMany
    {
        return $this->belongsToMany(Coupon::class)->withPivot('used_at');
    }

    public function cart(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_user');
    }

    public function createdCodes(): HasMany
    {
        return $this->hasMany(StylistInvitationCode::class, 'created_by');
    }

    public function usedCodes(): HasOne
    {
        return $this->hasOne(StylistInvitationCode::class, 'used_by');
    }
}
