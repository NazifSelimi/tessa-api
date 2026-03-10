<?php

namespace App\Services;

use App\Models\Brand;

class BrandService
{
    /**
     * List all brands ordered by name.
     */
    public function listAll()
    {
        return Brand::orderBy('name')
            ->get()
            ->map(fn (Brand $brand) => [
                'id' => $brand->id,
                'name' => $brand->name,
                'createdAt' => $brand->created_at,
                'updatedAt' => $brand->updated_at,
            ]);
    }

    /**
     * Create a new brand.
     */
    public function create(array $validated): Brand
    {
        return Brand::create($validated);
    }

    /**
     * Update an existing brand.
     */
    public function update($id, array $validated): Brand
    {
        $brand = Brand::findOrFail($id);
        $brand->update($validated);

        return $brand;
    }

    /**
     * Delete a brand if it has no associated products.
     */
    public function delete($id): array
    {
        $brand = Brand::findOrFail($id);

        if ($brand->products()->exists()) {
            return ['deleted' => false, 'error' => 'Cannot delete brand with existing products'];
        }

        $brand->delete();

        return ['deleted' => true];
    }
}
