<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PricingService;
use App\Support\ApiResponse;

/**
 * Shop numbers the website shows before a cart is priced: delivery cost and
 * the free-shipping amount (owner rule Q6). The cart and checkout use the
 * server's price check (/cart/quote) for the real figures.
 */
class ShopSettingsController extends Controller
{
    public function __invoke(PricingService $pricing)
    {
        return ApiResponse::ok($pricing->shippingSettings());
    }
}
