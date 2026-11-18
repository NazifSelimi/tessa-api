<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\FeatureService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminFeatureController extends Controller
{
    public function __construct(private readonly FeatureService $features) {}

    public function index()
    {
        return ApiResponse::ok($this->features->adminList());
    }

    /** On/off switches. */
    public function update(Request $request)
    {
        $data = $request->validate([
            'features' => ['required', 'array'],
            'features.*' => ['boolean'],
        ]);
        $unknown = array_diff(array_keys($data['features']), array_keys($this->features->registry()));
        if ($unknown !== []) {
            return ApiResponse::error('Unknown feature: ' . implode(', ', $unknown), 422);
        }
        $this->features->update($data['features']);

        return ApiResponse::ok($this->features->adminList(), 200, null, 'Features saved');
    }
}
