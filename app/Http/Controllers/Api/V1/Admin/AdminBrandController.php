<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BrandResource;
use App\Services\BrandService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminBrandController extends Controller
{
    public function __construct(
        protected BrandService $brandService
    ) {}

    /**
     * Create new brand.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:brands,name'],
        ]);

        $brand = $this->brandService->create($validated);

        return ApiResponse::ok(
            new BrandResource($brand),
            201,
            [],
            'Brand created successfully'
        );
    }

    /**
     * Update existing brand.
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:brands,name,' . $id],
        ]);

        $brand = $this->brandService->update($id, $validated);

        return ApiResponse::ok(
            new BrandResource($brand),
            200,
            [],
            'Brand updated successfully'
        );
    }

    /**
     * Delete brand.
     */
    public function destroy($id)
    {
        $result = $this->brandService->delete($id);

        if (!$result['deleted']) {
            return ApiResponse::error($result['error'], 400);
        }

        return ApiResponse::ok(null, 200, [], 'Brand deleted successfully');
    }
}
