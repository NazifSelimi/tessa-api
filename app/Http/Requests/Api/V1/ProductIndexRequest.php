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
            // Comma-separated category ids, e.g. "3,8,11" (collection pages fall back to these).
            'category_ids' => ['nullable', 'string', 'max:200', 'regex:/^\d+(,\d+)*$/'],
            'collection'  => ['nullable', 'string', 'max:100', 'exists:product_collections,slug'],
            'min_price'   => ['nullable', 'numeric', 'min:0'],
            'max_price'   => ['nullable', 'numeric', 'min:0'],
            'search'      => ['nullable', 'string', 'max:255'],
            'on_sale'     => ['nullable', 'boolean'],
            'in_stock'    => ['nullable', 'boolean'],
            'review'      => ['nullable', 'string', 'in:in_review,ready'],
            // Admin work queues: products missing a retail or salon price, and low / zero stock.
            'price_status' => ['nullable', 'string', 'in:missing'],
            'stock'       => ['nullable', 'string', 'in:low,out'],
            // Admin: 'hidden' (nobody), 'limited' (not everyone), or one audience it is shown to.
            'visibility'  => ['nullable', 'string', 'in:hidden,limited,everyone,guests,customers,stylists'],
            // 'all' lists every shade of a colour line separately (e.g. for prerendering).
            'shades'      => ['nullable', 'string', 'in:all'],
            'sort'        => ['nullable', 'string', 'in:name_asc,name_desc,price_asc,price_desc,newest,featured'],
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:1000'],
            'perPage'     => ['nullable', 'integer', 'min:1', 'max:1000'],
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

        // Admin pickers (offers, studio) need the whole catalogue in one page.
        return min(max($perPage, 1), $this->user()?->isAdmin() ? 1000 : 500);
    }
}
