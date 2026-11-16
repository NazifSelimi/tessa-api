<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RecommendationRequest;
use App\Http\Resources\BundleResource;
use App\Http\Resources\RecommendedProductResource;
use App\Models\Product;
use App\Services\RecommendationService;
use App\Support\ApiUserResolver;
use App\Support\ApiResponse;

class RecommendationController extends Controller
{
    public function __construct(
        protected RecommendationService $recommendationService
    ) {}

    public function __invoke(RecommendationRequest $request)
    {
        $viewer = ApiUserResolver::fromRequest($request);
        $result = $this->recommendationService->getRecommendations(
            hairTypeIds: $request->validated('hair_type_ids'),
            needIds:     $request->validated('concerns') ?? [],
            steps:       $request->validated('steps') ?? [],
            budgetRange: $request->validated('budget_range'),
            // Admins preview the quiz as a stylist would see it.
            audience: $viewer?->isAdmin() ? 'stylists' : Product::audienceOf($viewer),
        );

        return ApiResponse::ok([
            'products' => RecommendedProductResource::collection(collect($result['products']))->resolve(),
            'routine' => collect($result['routine'])->map(fn (array $entry) => [
                'step' => $entry['step'],
                'product' => (new RecommendedProductResource($entry['product']))->resolve(),
                'companion' => $entry['companion'] ? (new RecommendedProductResource($entry['companion']))->resolve() : null,
            ])->values(),
            'steps' => $result['steps'],
            'bundles' => $result['bundles']->map(fn ($bundle) => (new BundleResource($bundle))->resolve())->values(),
        ]);
    }
}
