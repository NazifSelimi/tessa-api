<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ProductIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brand_id'    => ['nullable', 'integer', 'exists:brands,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'min_price'   => ['nullable', 'numeric', 'min:0'],
            'max_price'   => ['nullable', 'numeric', 'min:0'],
            'search'      => ['nullable', 'string', 'max:255'],
            'on_sale'     => ['nullable', 'boolean'],
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:100'],
            'perPage'     => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function filters(): array
    {
        return collect($this->validated())
            ->except(['per_page', 'perPage'])
            ->toArray();
    }

    public function perPage(): int
    {
        $perPage = (int) $this->input('perPage', $this->input('per_page', 20));

        return min(max($perPage, 1), 100);
    }
}
