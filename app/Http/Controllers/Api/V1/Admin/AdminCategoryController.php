<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Services\CategoryService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminCategoryController extends Controller
{
    public function __construct(
        protected CategoryService $categoryService
    ) {}

    /**
     * Create new category.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:categories,name'],
        ]);

        $category = $this->categoryService->create($validated);

        return ApiResponse::ok(
            new CategoryResource($category),
            201,
            [],
            'Category created successfully'
        );
    }

    /**
     * Update existing category.
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:categories,name,' . $id],
        ]);

        $category = $this->categoryService->update($id, $validated);

        return ApiResponse::ok(
            new CategoryResource($category),
            200,
            [],
            'Category updated successfully'
        );
    }

    /**
     * Delete category.
     */
    public function destroy($id)
    {
        $result = $this->categoryService->delete($id);

        if (!$result['deleted']) {
            return ApiResponse::error($result['error'], 400);
        }

        return ApiResponse::ok(null, 200, [], 'Category deleted successfully');
    }
}
