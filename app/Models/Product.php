<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Product extends Model
{
    use HasFactory;

    /**
     * Values the supplier importer gives products it could not match. Such a
     * product stays in review (admin only) until it has a real photo, brand
     * and category.
     */
    public const PLACEHOLDER_IMAGE_PATH = 'catalog-placeholders/';
    public const REVIEW_BRAND_NAMES = ['supplier catalogue'];
    public const REVIEW_CATEGORY_NAMES = ['catalogue review'];

    protected $fillable = [
        'name',
        'brand_id',
        'category_id',
        'quantity',
        'price',
        'stylist_price',
        'cost_price',
        'stylist_only',
        'visible_to_guests',
        'visible_to_customers',
        'visible_to_stylists',
    ];

    /** Who an admin can show a product to; each maps to a visible_to_* column. */
    public const AUDIENCES = ['guests', 'customers', 'stylists'];

    // Purchase cost is admin-only; ProductResource adds it for admins.
    protected $hidden = ['cost_price'];

    protected $casts = [
        'price' => 'decimal:2',
        'stylist_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'quantity' => 'integer',
        'stylist_only' => 'boolean',
        'visible_to_guests' => 'boolean',
        'visible_to_customers' => 'boolean',
        'visible_to_stylists' => 'boolean',
    ];

    protected static function booted(): void
    {
        // stylist_only is the old single switch (still set by importers and the
        // classifier). Keep it and the audience columns describing the same thing.
        static::saving(function (Product $product) {
            $audienceChanged = $product->isDirty(['visible_to_guests', 'visible_to_customers']);

            if (!$audienceChanged && $product->isDirty('stylist_only')) {
                $public = !$product->stylist_only;
                $product->visible_to_guests = $public;
                $product->visible_to_customers = $public;
                if ($product->stylist_only) {
                    $product->visible_to_stylists = true;
                }
                return;
            }

            $guests = $product->visible_to_guests ?? true;
            $customers = $product->visible_to_customers ?? true;
            $product->stylist_only = !$guests && !$customers;
        });
    }

    /* ========================================= */
    /* AUDIENCE VISIBILITY                       */
    /* ========================================= */

    /** 'admin', 'stylists', 'customers' or 'guests' for the person browsing. */
    public static function audienceOf(?User $viewer): string
    {
        return match (true) {
            $viewer === null => 'guests',
            $viewer->isAdmin() => 'admin',
            $viewer->isStylist() => 'stylists',
            default => 'customers',
        };
    }

    /** @return list<string> the audiences ticked for this product; empty = hidden. */
    public function visibleTo(): array
    {
        return array_values(array_filter(
            self::AUDIENCES,
            fn (string $audience) => (bool) ($this->{'visible_to_' . $audience} ?? true)
        ));
    }

    /** @param list<string> $audiences */
    public function setVisibleTo(array $audiences): static
    {
        foreach (self::AUDIENCES as $audience) {
            $this->{'visible_to_' . $audience} = in_array($audience, $audiences, true);
        }

        return $this;
    }

    /** Only the products the admin has opened to this viewer's audience. */
    public function scopeVisibleTo(Builder $query, ?User $viewer): Builder
    {
        $audience = self::audienceOf($viewer);

        return $audience === 'admin'
            ? $query
            : $query->where($query->qualifyColumn('visible_to_' . $audience), true);
    }

    /**
     * What this viewer may see and buy: opened to their audience, priced for
     * them and ready for sale. Admins see everything.
     */
    public function scopeAvailableTo(Builder $query, ?User $viewer): Builder
    {
        if ($viewer?->isAdmin()) {
            return $query;
        }

        return $query->visibleTo($viewer)->pricedFor($viewer?->isStylist() ?? false)->readyForSale();
    }

    public function isVisibleTo(?User $viewer): bool
    {
        $audience = self::audienceOf($viewer);

        return $audience === 'admin' || (bool) ($this->{'visible_to_' . $audience} ?? true);
    }

    public function isAvailableTo(?User $viewer): bool
    {
        if ($viewer?->isAdmin()) {
            return true;
        }

        return $this->isVisibleTo($viewer)
            && $this->isPricedFor($viewer?->isStylist() ?? false)
            && !$this->isInReview();
    }

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

    public function productFamily(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class);
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
        return $this->morphMany(Image::class, 'imageable')
            ->orderBy('sort_order')
            ->orderBy('id');
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

    public function catalogCollections(): BelongsToMany
    {
        return $this->belongsToMany(ProductCollection::class, 'product_collection_product')
            ->withPivot(['mapping_status', 'source', 'notes'])
            ->withTimestamps()
            ->wherePivot('mapping_status', 'confirmed')
            ->orderBy('product_collections.sort_priority');
    }

    public function collectionAssignments(): BelongsToMany
    {
        return $this->belongsToMany(ProductCollection::class, 'product_collection_product')
            ->withPivot(['mapping_status', 'source', 'notes'])
            ->withTimestamps()
            ->orderBy('product_collections.sort_priority');
    }

    /* ========================================= */
    /* ACCESSORS                                 */
    /* ========================================= */

    public function getDescriptionAttribute(): ?string
    {
        $locale = \App\Support\ContentLocale::current();

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
        $regular = (float) ($isStylist ? $this->stylist_price : $this->price);

        // A sale may only lower the price. Salons keep their own price when it
        // is already below the sale, and a sale never makes an unpriced row
        // purchasable for that audience.
        if ($regular > 0 && $this->hasActiveSale() && (float) $this->sale->sale_price > 0) {
            return min($regular, (float) $this->sale->sale_price);
        }

        return $regular;
    }

    /**
     * Imported rows can arrive without a price for one audience (for example a
     * salon-only item with a stylist price but no retail price). Such a row must
     * never be offered to that audience, or it could be ordered for free.
     */
    public function scopePricedFor(Builder $query, bool $isStylist = false): Builder
    {
        return $query->where($query->qualifyColumn($isStylist ? 'stylist_price' : 'price'), '>', 0);
    }

    public function isPricedFor(bool $isStylist = false): bool
    {
        return $this->resolvePrice($isStylist) > 0;
    }

    /**
     * Products customers and stylists may see: a real photo, a real brand and
     * a real category. Everything else is in review and visible to admins only.
     */
    public function scopeReadyForSale(Builder $query): Builder
    {
        $placeholder = '%' . self::PLACEHOLDER_IMAGE_PATH . '%';

        return $query
            ->whereHas('images', fn (Builder $images) => $images
                ->where('name', 'not like', $placeholder)
                ->where(fn (Builder $url) => $url->whereNull('url')->orWhere('url', 'not like', $placeholder)))
            ->whereDoesntHave('brand', fn (Builder $brand) => $brand->whereIn('name', self::REVIEW_BRAND_NAMES))
            ->whereDoesntHave('category', fn (Builder $category) => $category->whereIn('name', self::REVIEW_CATEGORY_NAMES));
    }

    /**
     * Why the product is in review; empty when it is ready for sale.
     *
     * @return list<string>
     */
    public function reviewReasons(): array
    {
        $reasons = [];

        if ($this->images->reject(fn (Image $image) => $image->isPlaceholder())->isEmpty()) {
            $reasons[] = 'no_photo';
        }
        if (in_array(mb_strtolower((string) $this->brand?->name), self::REVIEW_BRAND_NAMES, true)) {
            $reasons[] = 'no_brand';
        }
        if (in_array(mb_strtolower((string) $this->category?->name), self::REVIEW_CATEGORY_NAMES, true)) {
            $reasons[] = 'no_category';
        }

        return $reasons;
    }

    public function isInReview(): bool
    {
        return $this->reviewReasons() !== [];
    }

    /**
     * Images in display order. The 2026-09 catalogue audit matched generated
     * images by product name and many show the wrong item (a bleach tub on a
     * hair colour), so where an original photo exists those audit images are
     * left out and the original photo is shown, as on tessa.mk.
     */
    public function storefrontImages(): Collection
    {
        $images = $this->images->reject(fn (Image $image) => $image->isPlaceholder());

        if ($images->contains(fn (Image $image) => ($image->variant ?? 'legacy') === 'legacy')) {
            $images = $images->reject(fn (Image $image) => $image->isFromCatalogAudit());
        }

        return $images->values();
    }

    /** Public URL of the hair-swatch photo used in the shade picker. */
    public function swatchUrl(): ?string
    {
        return $this->swatch_image ? asset('storage/' . ltrim($this->swatch_image, '/')) : null;
    }

    /**
     * The main photo, as its small card copy when one was made from it. A photo
     * an admin uploaded wins; extra angles (metadata.angle) never do, so a
     * tessa.mk photo stays first when angles are added after it.
     */
    public function primaryImage(): ?Image
    {
        $images = $this->storefrontImages();
        $photos = $images->filter(fn (Image $image) => in_array($image->variant ?? 'legacy', ['legacy', 'detail'], true));
        $main = $photos->first(fn (Image $image) => $image->variant === 'detail' && empty($image->metadata['angle']))
            ?? $photos->first();

        if (!$main) {
            return $images->firstWhere('variant', 'card') ?? $images->first() ?? $this->images->first();
        }

        $cards = $images->where('variant', 'card');
        $ownCard = $cards->first(fn (Image $image) => (string) ($image->metadata['derived_from'] ?? '') === (string) $main->id);
        // Cards made at upload time are not linked by id; use one only when no card points at a specific photo.
        $unlinkedCard = $cards->every(fn (Image $image) => !is_numeric($image->metadata['derived_from'] ?? null)) ? $cards->first() : null;

        return $ownCard ?? $unlinkedCard ?? $main;
    }

    public function inStock(int $requestedQty = 1): bool
    {
        return $this->quantity >= $requestedQty;
    }
}
