<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class HairConcern extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'slug',
        'group',
        'gated',
        'sort_order',
    ];

    protected $casts = ['gated' => 'boolean', 'sort_order' => 'integer'];

    /* ========================================= */
    /* RELATIONSHIPS                             */
    /* ========================================= */

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_hair_concern');
    }
}
