<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\HairConcern;
use App\Models\HairType;
use App\Support\ApiResponse;

class HairProfileOptionController extends Controller
{
    public function __invoke()
    {
        return ApiResponse::ok([
            'hairTypes' => HairType::query()->orderBy('id')->get(['id', 'name'])->values(),
            'hairConcerns' => HairConcern::query()->orderBy('id')->get(['id', 'name'])->values(),
        ]);
    }
}
