<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContentBlock extends Model
{
    use HasFactory;

    protected $fillable = [
        'key', 'page', 'section', 'kind', 'eyebrow', 'title', 'body',
        'image_url', 'action_label', 'action_url', 'items', 'is_published', 'sort_order',
    ];

    protected $casts = [
        'items' => 'array',
        'is_published' => 'boolean',
    ];
}
