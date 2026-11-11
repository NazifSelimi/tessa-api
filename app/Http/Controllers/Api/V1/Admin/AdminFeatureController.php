<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\FeatureService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminFeatureController extends Controller
{
    public function __construct(private readonly FeatureService $features) {}

    public function index()
    {
        return ApiResponse::ok($this->features->adminList());
    }

    /** Switches, per-feature plan levels and the shop's plan (no billing: set by hand). */
    public function update(Request $request)
    {
        $data = $request->validate([
            'features' => ['sometimes', 'array'],
            'features.*' => ['boolean'],
            'plans' => ['sometimes', 'array'],
            'plans.*' => [Rule::in(FeatureService::PLANS)],
            'shop_plan' => ['sometimes', Rule::in(FeatureService::PLANS)],
        ]);
        $keys = array_keys($this->features->registry());
        $unknown = array_diff(array_merge(array_keys($data['features'] ?? []), array_keys($data['plans'] ?? [])), $keys);
        if ($unknown !== []) {
            return ApiResponse::error('Unknown feature: ' . implode(', ', $unknown), 422);
        }
        $this->features->update($data['features'] ?? []);
        $this->features->updatePlanLevels($data['plans'] ?? []);
        if (isset($data['shop_plan'])) {
            $this->features->setShopPlan($data['shop_plan']);
        }

        return ApiResponse::ok($this->features->adminList(), 200, null, 'Features saved');
    }
}
