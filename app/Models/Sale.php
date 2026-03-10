<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sale extends Model
{
    use HasFactory;
    protected $fillable = [
        'product_id',
        'sale_price',
        'start_date',
        'end_date'
    ];
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
    public function scopeActive($query)
    {

        return $query->where('start_date', '<=', now())
            ->where('end_date', '>=', now());
    }
}
