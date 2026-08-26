<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductCollection extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'title',
        'description',
        'sort_priority',
        'is_active',
        'default_routine_roles',
        'supported_category_names',
    ];

    protected $casts = [
        'sort_priority' => 'integer',
        'is_active' => 'boolean',
        'default_routine_roles' => 'array',
        'supported_category_names' => 'array',
    ];

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_collection_product')
            ->withPivot(['mapping_status', 'source', 'notes'])
            ->withTimestamps()
            ->wherePivot('mapping_status', 'confirmed');
    }

    public function productAssignments(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_collection_product')
            ->withPivot(['mapping_status', 'source', 'notes'])
            ->withTimestamps();
    }
}
