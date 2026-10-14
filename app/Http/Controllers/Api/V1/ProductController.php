<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductIndexRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\ProductService;
use App\Support\ApiUserResolver;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * Storefront catalogue entry point. ProductService owns queries and
     * visibility, while ProductResource remains the client-facing DTO.
     */
    public function __construct(
        protected ProductService $productService
    ) {}

    /**
     * Powers the product-listing screen and its filters.
     *
     * The optional bearer token affects stylist-only visibility; it never lets
     * client filter input bypass the ProductIndexRequest allow-list.
     */
    public function index(ProductIndexRequest $request)
    {
        $viewer = ApiUserResolver::fromRequest($request);
        $products = $this->productService->paginate(
            $request->filters(),
            $request->perPage(),
            $viewer
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

    /**
     * Powers product detail. Route binding finds the row; canView prevents a
     * hidden stylist-only row from becoming observable by an ordinary visitor.
     */
    public function show(Product $product)
    {
        $viewer = ApiUserResolver::fromRequest(request());

        if (!$this->productService->canView($product, $viewer)) {
            return ApiResponse::error('Product not found', 404);
        }

        return ApiResponse::ok(
            new ProductResource(
                $this->productService->find($product)
            )
        );
    }

    /**
     * Powers the related-products rail on a product detail screen.
     */
    public function related(Request $request, Product $product)
    {
        $viewer = ApiUserResolver::fromRequest($request);

        if (!$this->productService->canView($product, $viewer)) {
            return ApiResponse::error('Product not found', 404);
        }

        $limit = (int) $request->query('limit', 4);
        $limit = min(max($limit, 1), 20);

        $related = $this->productService->related($product, $limit, $viewer);

        return ApiResponse::ok(
            ProductResource::collection($related)->resolve()
        );
    }

    /**
     * Powers the featured/latest storefront rail. The limit clamp is a public
     * API safety boundary, not a presentation preference.
     */
    public function featured(Request $request)
    {
        $viewer = ApiUserResolver::fromRequest($request);
        $limit = (int) $request->query('limit', 8);
        $limit = min(max($limit, 1), 20);

        $latest = $this->productService->latest($limit, $viewer);

        return ApiResponse::ok(
            ProductResource::collection($latest)->resolve()
        );
    }

    /**
     * Powers catalogue search. An empty search is intentionally cheap and
     * returns no records instead of issuing a broad database query.
     */
    public function search(Request $request)
    {
        $viewer = ApiUserResolver::fromRequest($request);
        $query = (string) $request->query('q', '');
        $limit = (int) $request->query('limit', 10);
        $limit = min(max($limit, 1), 50);

        if ($query === '') {
            return ApiResponse::ok([]);
        }

        $results = $this->productService->search($query, $limit, $viewer);

        return ApiResponse::ok(
            ProductResource::collection($results)->resolve()
        );
    }
}
