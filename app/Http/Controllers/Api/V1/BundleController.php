<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BundleResource;
use App\Models\Bundle;
use App\Support\ApiResponse;
use App\Support\ApiUserResolver;
use Illuminate\Http\Request;

class BundleController extends Controller
{
    public function index(Request $request)
    {
        $viewer = ApiUserResolver::fromRequest($request);

        $bundles = Bundle::query()
            ->with(['products.images'])
            ->where('is_dynamic', false)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->when(!$viewer?->isStylist() && !$viewer?->isAdmin(), fn ($query) => $query->where('audience', 'all'))
            ->orderByDesc('is_featured')
            ->latest()
            ->get();

        return ApiResponse::ok(BundleResource::collection($bundles)->resolve());
    }
}
