<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'brand_id',
        'category_id',
        'quantity',
        'price',
        'stylist_price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stylist_price' => 'decimal:2',
        'quantity' => 'integer',
    ];

    /* ========================================= */
    /* RELATIONSHIPS                             */
    /* ========================================= */

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class)->withTimestamps();
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable');
    }

    public function sale(): HasOne
    {
        return $this->hasOne(Sale::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'product_user');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ProductTranslation::class);
    }

    public function hairTypes(): BelongsToMany
    {
        return $this->belongsToMany(HairType::class, 'product_hair_type');
    }

    public function hairConcerns(): BelongsToMany
    {
        return $this->belongsToMany(HairConcern::class, 'product_hair_concern');
    }

    public function bundles(): BelongsToMany
    {
        return $this->belongsToMany(Bundle::class, 'bundle_products')
            ->withPivot('quantity');
    }

    /* ========================================= */
    /* ACCESSORS                                 */
    /* ========================================= */

    public function getDescriptionAttribute(): ?string
    {
        $locale = app()->getLocale();

        $translation = $this->translations
            ->firstWhere('locale', $locale);

        return $translation?->description;
    }

    /* ========================================= */
    /* BUSINESS HELPERS                          */
    /* ========================================= */

    public function hasActiveSale(): bool
    {
        if (!$this->sale) {
            return false;
        }

        return now()->between(
            $this->sale->start_date,
            $this->sale->end_date
        );
    }

    public function resolvePrice(bool $isStylist = false): float
    {
        // Sale overrides everything
        if ($this->hasActiveSale()) {
            return (float) $this->sale->sale_price;
        }

        return (float) (
        $isStylist
            ? $this->stylist_price
            : $this->price
        );
    }

    public function inStock(int $requestedQty = 1): bool
    {
        return $this->quantity >= $requestedQty;
    }
}
