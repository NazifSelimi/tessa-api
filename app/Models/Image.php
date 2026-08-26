<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Image extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'url',
        'alt',
        'sort_order',
        'variant',
        'background',
        'review_status',
        'metadata',
        'imageable_type',
        'imageable_id',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function delete()
    {
        // Delete the image file from storage if needed
        if (is_string($this->name) && !str_starts_with($this->name, 'http')) {
            Storage::delete('public/images/' . ltrim($this->name, '/'));
        }


        // Delete the image record from the database
        return parent::delete();
    }

    public function imageable():MorphTo
    {
        return $this->morphTo();
    }

    public function product():BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function publicUrl(): ?string
    {
        if (!is_string($this->name) || $this->name === '') {
            return null;
        }

        if (str_starts_with($this->name, 'http')) {
            return $this->name;
        }

        return asset('storage/images/' . ltrim($this->name, '/'));
    }
}
/*
 * <?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Image extends Model
{
    protected $fillable = [
        'product_id',
        'path',
    ];

    protected static function booted(): void
    {
        static::deleting(function (Image $image) {
            if ($image->path) {
                Storage::disk('public')->delete($image->path);
            }
        });
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
*/
