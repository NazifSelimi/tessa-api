<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\FeatureService;
use App\Support\ApiResponse;

class FeatureController extends Controller
{
    /** On/off state of every switchable feature (public: the shop hides what is off). */
    public function __invoke(FeatureService $features)
    {
        return ApiResponse::ok($features->states());
    }
}
