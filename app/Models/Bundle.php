<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Bundle extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'image_path',
        'is_dynamic',
        'discount_percentage',
        'audience',
        'promotion_type',
        'product_family_id',
        'product_family_ids',
        'tiers',
        'bundle_price',
        'is_active',
        'is_featured',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'is_dynamic' => 'boolean',
        'tiers' => 'array',
        'product_family_ids' => 'array',
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

    public const MIX_MATCH = 'mix_match';

    /** Colour line a "pick any colours" offer counts. */
    public function family(): BelongsTo
    {
        return $this->belongsTo(ProductFamily::class, 'product_family_id');
    }

    public function isMixMatch(): bool
    {
        return $this->promotion_type === self::MIX_MATCH;
    }

    /** A gift / free line (not a product a "pick any" offer counts): flagged, or with free units. */
    public static function isGiftLine($pivot): bool
    {
        return (bool) $pivot->is_bonus || (int) $pivot->bonus_quantity > 0;
    }

    /** Every colour line the offer counts: the main one plus any extra lines. */
    public function familyIds(): array
    {
        return array_values(array_unique(array_filter(array_map('intval',
            array_merge([$this->product_family_id], $this->product_family_ids ?? [])))));
    }

    /**
     * Reward steps, smallest first: [{min, free, gift, gift_qty, gift_ids}].
     * gift: the stylist picks gift_qty units from the offer's gift list.
     * gift_ids: this step's own gifts, one of each (e.g. 30 tubes = brush).
     */
    public function sortedTiers(): array
    {
        $tiers = array_values(array_filter($this->tiers ?? [], fn ($tier) => (int) ($tier['min'] ?? 0) > 0));
        usort($tiers, fn ($a, $b) => (int) $a['min'] <=> (int) $b['min']);

        return array_map(fn ($tier) => [
            'min' => (int) $tier['min'],
            'free' => max(0, (int) ($tier['free'] ?? 0)),
            'gift' => (bool) ($tier['gift'] ?? false),
            'gift_qty' => max(1, (int) ($tier['gift_qty'] ?? 1)),
            'gift_ids' => array_values(array_map('strval', $tier['gift_ids'] ?? [])),
        ], $tiers);
    }

    /** Running now: switched on and inside its dates. */
    public function isRunning(): bool
    {
        return $this->is_active && ! $this->is_dynamic
            && (! $this->starts_at || ! $this->starts_at->isFuture())
            && (! $this->ends_at || ! $this->ends_at->isPast());
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'bundle_products')
            ->withPivot('quantity', 'is_bonus', 'bonus_quantity');
    }

    public const AUDIENCES = ['all', 'stylist', 'customer'];

    /** Audiences whose offers this viewer sees (admins see all). */
    public static function audiencesFor(?User $user): array
    {
        if ($user?->isAdmin()) {
            return self::AUDIENCES;
        }

        return $user?->isStylist() ? ['all', 'stylist'] : ['all', 'customer'];
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

        if ($user?->isAdmin()) {
            return true;
        }

        // all = everyone, stylist = salons only, customer = shoppers and guests only.
        return match ($this->audience) {
            'stylist' => (bool) $user?->isStylist(),
            'customer' => ! $user?->isStylist(),
            default => true,
        };
    }
}
