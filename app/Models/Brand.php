<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
    ];
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function productLines(): HasMany
    {
        return $this->hasMany(ProductLine::class);
    }

    public function productFamilies(): HasMany
    {
        return $this->hasMany(ProductFamily::class);
    }

}
