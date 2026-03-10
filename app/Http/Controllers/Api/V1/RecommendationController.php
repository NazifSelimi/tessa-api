<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RecommendationRequest;
use App\Http\Resources\BundleResource;
use App\Http\Resources\RecommendedProductResource;
use App\Services\RecommendationService;
use App\Support\ApiResponse;

class RecommendationController extends Controller
{
    public function __construct(
        protected RecommendationService $recommendationService
    ) {}

    public function __invoke(RecommendationRequest $request)
    {
        $result = $this->recommendationService->getRecommendations(
            hairTypeId:  $request->validated('hair_type_id'),
            concerns:    $request->validated('concerns'),
            budgetRange: $request->validated('budget_range'),
        );

        return ApiResponse::ok([
            'products' => RecommendedProductResource::collection(
                collect($result['products'])
            )->resolve(),
            'bundles' => collect($result['bundles'])->map(function ($bundle) {
                if (is_array($bundle)) {
                    return $bundle; // dynamic virtual bundle
                }
                return (new BundleResource($bundle))->resolve();
            })->values(),
        ]);
    }
}
