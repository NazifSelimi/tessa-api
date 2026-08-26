<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductCollectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'sortPriority' => (int) $this->sort_priority,
            'routineRoles' => $this->default_routine_roles ?? [],
            'supportedCategoryNames' => $this->supported_category_names ?? [],
            'productCount' => $this->when(isset($this->products_count), (int) $this->products_count),
        ];
    }
}
