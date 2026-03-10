<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\BrandService;
use App\Support\ApiResponse;

class BrandController extends Controller
{
    public function __construct(
        protected BrandService $brandService
    ) {}

    public function index()
    {
        return ApiResponse::ok($this->brandService->listAll());
    }
}
