<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductCollectionResource;
use App\Models\ProductCollection;
use App\Support\ApiResponse;
use App\Support\ApiUserResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ProductCollectionController extends Controller
{
    public function index(Request $request)
    {
        $viewer = ApiUserResolver::fromRequest($request);

        $collections = ProductCollection::query()
            ->where('is_active', true)
            ->withCount([
                'products as products_count' => function (Builder $productQuery) use ($viewer): void {
                    if (!$viewer?->isStylist() && !$viewer?->isAdmin()) {
                        $productQuery->where('stylist_only', false);
                    }
                },
            ])
            ->orderBy('sort_priority')
            ->get();

        return ApiResponse::ok(ProductCollectionResource::collection($collections)->resolve());
    }
}
