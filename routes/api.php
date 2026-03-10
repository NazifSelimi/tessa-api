<?php

use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\CouponController;
use App\Http\Controllers\Api\V1\StylistRequestController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\StylistRequestAdminController;
use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\Admin\AdminProductController;
use App\Http\Controllers\Api\V1\Admin\AdminCouponController;
use App\Http\Controllers\Api\V1\Admin\AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\AdminBrandController;
use App\Http\Controllers\Api\V1\Admin\AdminReportController;
use App\Http\Controllers\Api\V1\Stylist\StylistCodeController;
use App\Http\Controllers\Api\V1\Stylist\StylistDashboardController;
use App\Http\Controllers\Api\V1\Stylist\StylistOrderController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\RecommendationController;
use App\Http\Controllers\Api\V1\QuickOrderController;

Route::prefix('v1')->middleware('throttle:api')->group(function () {

    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/featured', [ProductController::class, 'featured']);
    Route::get('products/search', [ProductController::class, 'search']);
    Route::get('products/quick-order', QuickOrderController::class);
    Route::get('products/{product}/related', [ProductController::class, 'related']);
    Route::get('products/{product}', [ProductController::class, 'show']);
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('brands', [BrandController::class, 'index']);
    Route::post('coupons/validate', [CouponController::class, 'validate']);
    Route::post('recommendations', RecommendationController::class);

    Route::middleware('auth:sanctum')->group(function () {

        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::put('/auth/profile', [AuthController::class, 'updateProfile']);

        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);

        Route::post('/checkout', [CheckoutController::class, 'checkout']);

        // Stylist Requests
        Route::post('/stylist-requests', [StylistRequestController::class, 'store']);
        Route::get('/stylist-requests/status', [StylistRequestController::class, 'status']);

        // Admin Routes
        Route::prefix('admin')->middleware('role:admin')->group(function () {
            // Dashboard
            Route::get('/dashboard', [DashboardController::class, 'index']);
            
            // User Management
            Route::get('/users', [AdminUserController::class, 'index']);
            Route::get('/users/{id}', [AdminUserController::class, 'show']);
            Route::put('/users/{id}', [AdminUserController::class, 'update']);
            Route::delete('/users/{id}', [AdminUserController::class, 'destroy']);
            
            // Product Management
            Route::post('/products', [AdminProductController::class, 'store']);
            Route::put('/products/{id}', [AdminProductController::class, 'update']);
            Route::delete('/products/{id}', [AdminProductController::class, 'destroy']);
            Route::put('/products/{id}/stock', [AdminProductController::class, 'updateStock']);
            Route::post('/products/bulk-update', [AdminProductController::class, 'bulkUpdate']);
            
            // Coupon Management
            Route::get('/coupons', [AdminCouponController::class, 'index']);
            Route::get('/coupons/{id}', [AdminCouponController::class, 'show']);
            Route::post('/coupons', [AdminCouponController::class, 'store']);
            Route::put('/coupons/{id}', [AdminCouponController::class, 'update']);
            Route::delete('/coupons/{id}', [AdminCouponController::class, 'destroy']);
            
            // Category Management
            Route::post('/categories', [AdminCategoryController::class, 'store']);
            Route::put('/categories/{id}', [AdminCategoryController::class, 'update']);
            Route::delete('/categories/{id}', [AdminCategoryController::class, 'destroy']);
            
            // Brand Management
            Route::post('/brands', [AdminBrandController::class, 'store']);
            Route::put('/brands/{id}', [AdminBrandController::class, 'update']);
            Route::delete('/brands/{id}', [AdminBrandController::class, 'destroy']);
            
            // Order Management
            Route::get('/orders', [AdminOrderController::class, 'index']);
            Route::put('/orders/{id}/status', [AdminOrderController::class, 'updateStatus']);
            
            // Stylist Request Management
            Route::get('/stylist-requests', [StylistRequestAdminController::class, 'index']);
            Route::post('/stylist-requests/{id}/approve', [StylistRequestAdminController::class, 'approve']);
            Route::post('/stylist-requests/{id}/reject', [StylistRequestAdminController::class, 'reject']);
            
            // Reports & Analytics
            Route::get('/reports/sales', [AdminReportController::class, 'sales']);
            Route::get('/reports/products', [AdminReportController::class, 'products']);
        });
        
        // Stylist Routes
        Route::prefix('stylist')->middleware('role:stylist')->group(function () {
            // Dashboard
            Route::get('/dashboard', [StylistDashboardController::class, 'index']);
            
            // Distributor Codes
            Route::get('/codes', [StylistCodeController::class, 'index']);
            Route::post('/codes/generate', [StylistCodeController::class, 'generate']);
            Route::get('/codes/{code}/stats', [StylistCodeController::class, 'stats']);
            Route::put('/codes/{code}', [StylistCodeController::class, 'update']);
            
            // Orders
            Route::get('/orders', [StylistOrderController::class, 'index']);
        });
    });
});


