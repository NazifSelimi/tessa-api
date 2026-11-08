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

    public function update(Request $request)
    {
        $keys = array_keys($this->features->registry());
        $data = $request->validate([
            'features' => ['required', 'array'],
            'features.*' => ['boolean'],
        ]);
        $unknown = array_diff(array_keys($data['features']), $keys);
        if ($unknown !== []) {
            return ApiResponse::error('Unknown feature: ' . implode(', ', $unknown), 422);
        }
        $this->features->update($data['features']);

        return ApiResponse::ok($this->features->adminList(), 200, null, 'Features saved');
    }
}
