<?php

namespace App\Services;

use App\Models\Category;

class CategoryService
{
    /**
     * List all categories ordered by name.
     */
    public function listAll()
    {
        return Category::orderBy('name')
            ->get()
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'createdAt' => $category->created_at,
                'updatedAt' => $category->updated_at,
            ]);
    }

    /**
     * Create a new category.
     */
    public function create(array $validated): Category
    {
        return Category::create($validated);
    }

    /**
     * Update an existing category.
     */
    public function update($id, array $validated): Category
    {
        $category = Category::findOrFail($id);
        $category->update($validated);

        return $category;
    }

    /**
     * Delete a category if it has no associated products.
     */
    public function delete($id): array
    {
        $category = Category::findOrFail($id);

        if ($category->products()->exists()) {
            return ['deleted' => false, 'error' => 'Cannot delete category with existing products'];
        }

        $category->delete();

        return ['deleted' => true];
    }
}
