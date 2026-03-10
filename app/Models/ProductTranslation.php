<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductTranslation extends Model
{
    public $timestamps = false;
    protected $fillable = ['locale', 'description'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
