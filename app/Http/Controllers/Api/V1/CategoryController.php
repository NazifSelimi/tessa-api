<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CategoryService;
use App\Support\ApiResponse;
use App\Support\ApiUserResolver;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct(
        protected CategoryService $categoryService
    ) {}

    public function index(Request $request)
    {
        $viewer = ApiUserResolver::fromRequest($request);
        $isAdmin = $viewer !== null && (int) $viewer->role === 1;

        return ApiResponse::ok($this->categoryService->listAll($isAdmin));
    }
}
