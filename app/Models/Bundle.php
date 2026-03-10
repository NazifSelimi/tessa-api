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
    ];

    protected $casts = [
        'is_dynamic' => 'boolean',
        'discount_percentage' => 'decimal:2',
    ];

    /* ========================================= */
    /* RELATIONSHIPS                             */
    /* ========================================= */

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'bundle_products')
            ->withPivot('quantity');
    }
}
