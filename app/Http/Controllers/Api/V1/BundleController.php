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
        // Offers and colour deals are separate switches on /admin/features.
        $features = app(\App\Services\FeatureService::class);
        $offersOn = $features->enabled('bundles');
        $dealsOn = $features->enabled('colour_deals');
        if (! $offersOn && ! $dealsOn) {
            return ApiResponse::ok([]);
        }

        $bundles = Bundle::query()
            ->with(['products.images', 'family'])
            ->where('is_dynamic', false)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->whereIn('audience', Bundle::audiencesFor($viewer))
            ->when(! $offersOn, fn ($query) => $query->where('promotion_type', Bundle::MIX_MATCH))
            ->when(! $dealsOn, fn ($query) => $query->where('promotion_type', '!=', Bundle::MIX_MATCH))
            ->orderByDesc('is_featured')
            ->latest()
            ->get();

        return ApiResponse::ok(BundleResource::collection($bundles)->resolve());
    }
}
