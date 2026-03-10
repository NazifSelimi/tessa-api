<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class HairType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
    ];

    /* ========================================= */
    /* RELATIONSHIPS                             */
    /* ========================================= */

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_hair_type');
    }
}
