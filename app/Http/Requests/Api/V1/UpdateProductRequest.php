<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'stylist_price' => ['sometimes', 'numeric', 'min:0'],
            'quantity' => ['sometimes', 'integer', 'min:0'],
            'brand_id' => ['sometimes', 'integer', 'exists:brands,id'],
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'image' => ['sometimes', 'nullable', 'image', 'max:2048'],
            'description' => ['sometimes', 'nullable', 'string'],
            'locale' => ['nullable', 'string', 'max:10'],
        ];
    }
}
