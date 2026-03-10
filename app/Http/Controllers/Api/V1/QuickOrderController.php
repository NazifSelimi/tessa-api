<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Models\Product;
use Illuminate\Http\Request;

class QuickOrderController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'search'      => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'page'        => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Product::query()
            ->select(['id', 'name', 'price', 'stylist_price', 'quantity', 'category_id'])
            ->with(['images' => fn ($q) => $q->limit(1)]);

        if ($search = $request->query('search')) {
            $query->where('name', 'like', '%' . $search . '%');
        }

        if ($categoryId = $request->query('category_id')) {
            $query->where('category_id', $categoryId);
        }

        $products = $query->paginate(20);

        $data = collect($products->items())->map(fn (Product $p) => [
            'id'        => (string) $p->id,
            'name'      => $p->name,
            'price'     => (float) $p->price,
            'stylistPrice' => (float) $p->stylist_price,
            'thumbnail' => $p->images->isNotEmpty()
                ? asset('storage/images/' . $p->images->first()->name)
                : null,
        ])->values();

        return ApiResponse::ok($data, 200, [
            'current_page' => $products->currentPage(),
            'per_page'     => $products->perPage(),
            'total'        => $products->total(),
            'last_page'    => $products->lastPage(),
        ]);
    }
}
