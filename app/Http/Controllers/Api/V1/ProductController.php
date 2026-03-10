<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductIndexRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\ProductService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        protected ProductService $productService
    ) {}

    public function index(ProductIndexRequest $request)
    {
        $products = $this->productService->paginate(
            $request->filters(),
            $request->perPage()
        );

        return ApiResponse::ok(
            ProductResource::collection($products)->resolve(),
            200,
            [
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'last_page' => $products->lastPage(),
            ]
        );
    }

    public function show(Product $product)
    {
        return ApiResponse::ok(
            new ProductResource(
                $this->productService->find($product)
            )
        );
    }

    public function related(Request $request, Product $product)
    {
        $limit = (int) $request->query('limit', 4);
        $limit = min(max($limit, 1), 20);

        $related = $this->productService->related($product, $limit);

        return ApiResponse::ok(
            ProductResource::collection($related)->resolve()
        );
    }

    public function featured(Request $request)
    {
        $limit = (int) $request->query('limit', 8);
        $limit = min(max($limit, 1), 20);

        $latest = $this->productService->latest($limit);

        return ApiResponse::ok(
            ProductResource::collection($latest)->resolve()
        );
    }

    public function search(Request $request)
    {
        $query = (string) $request->query('q', '');
        $limit = (int) $request->query('limit', 10);
        $limit = min(max($limit, 1), 50);

        if ($query === '') {
            return ApiResponse::ok([]);
        }

        $results = Product::query()
            ->with(['brand', 'category', 'translations', 'images', 'sale'])
            ->where('name', 'like', '%' . $query . '%')
            ->limit($limit)
            ->get();

        return ApiResponse::ok(
            ProductResource::collection($results)->resolve()
        );
    }
}
