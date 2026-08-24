<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Bundle extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'is_dynamic',
        'discount_percentage',
        'audience',
        'promotion_type',
        'bundle_price',
        'is_active',
        'is_featured',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'is_dynamic' => 'boolean',
        'discount_percentage' => 'decimal:2',
        'bundle_price' => 'decimal:2',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /* ========================================= */
    /* RELATIONSHIPS                             */
    /* ========================================= */

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'bundle_products')
            ->withPivot('quantity', 'is_bonus');
    }

    public function isAvailableFor(?User $user): bool
    {
        if (!$this->is_active || $this->is_dynamic) {
            return false;
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->ends_at && $this->ends_at->isPast()) {
            return false;
        }

        return $this->audience !== 'stylist' || $user?->isStylist() || $user?->isAdmin();
    }
}
