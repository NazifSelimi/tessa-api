<?php

use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductCollectionController;
use App\Http\Controllers\Api\V1\CouponController;
use App\Http\Controllers\Api\V1\StylistRequestController;
use App\Http\Controllers\Api\V1\Admin\DashboardController;
use App\Http\Controllers\Api\V1\Admin\AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\StylistRequestAdminController;
use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\Admin\AdminProductController;
use App\Http\Controllers\Api\V1\Admin\CatalogueReviewController;
use App\Http\Controllers\Api\V1\Admin\AdminCouponController;
use App\Http\Controllers\Api\V1\Admin\AdminCategoryController;
use App\Http\Controllers\Api\V1\Admin\AdminBrandController;
use App\Http\Controllers\Api\V1\Admin\AdminDistributorController;
use App\Http\Controllers\Api\V1\Admin\AdminReportController;
use App\Http\Controllers\Api\V1\Stylist\StylistCodeController;
use App\Http\Controllers\Api\V1\Stylist\StylistDashboardController;
use App\Http\Controllers\Api\V1\Stylist\StylistOrderController;
use App\Http\Controllers\Api\V1\Stylist\StylistReferralController;
use App\Http\Controllers\Api\V1\Admin\AdminReferralController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\StylistInvitationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\RecommendationController;
use App\Http\Controllers\Api\V1\QuickOrderController;
use App\Http\Controllers\Api\V1\ContentBlockController;
use App\Http\Controllers\Api\V1\Admin\AdminContentBlockController;
use App\Http\Controllers\Api\V1\BundleController;
use App\Http\Controllers\Api\V1\HairProfileOptionController;
use App\Http\Controllers\Api\V1\Admin\AdminBundleController;
use App\Http\Controllers\Api\V1\Admin\AdminPricingController;
use App\Http\Controllers\Api\V1\CartQuoteController;
use App\Http\Controllers\Api\V1\Admin\CatalogImageCandidateController;
use App\Http\Controllers\Api\V1\Admin\HairQuizController;

/*
 * Keep route comments focused on feature ownership and authorization. Controller
 * and service comments belong beside the decision/query they explain so this
 * file remains the authoritative public API surface rather than a second copy
 * of business logic.
 */
Route::prefix('v1')->middleware('throttle:api')->group(function () {

    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::get('/auth/oauth/{provider}/redirect', [AuthController::class, 'socialRedirect']);
    Route::get('/auth/oauth/{provider}/callback', [AuthController::class, 'socialCallback']);
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:5,1'); // 5 attempts per minute
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword']);
    Route::get('/stylist-invitations/{token}', [StylistInvitationController::class, 'show'])
        ->middleware('throttle:10,1');
    Route::post('/stylist-invitations/{token}/activate', [StylistInvitationController::class, 'activate'])
        ->middleware('throttle:5,1');
    Route::get('products', [ProductController::class, 'index']);
    // This must appear before products/{product}; otherwise Laravel attempts
    // to resolve the literal "quick-order" as a product and returns 404.
    Route::get('products/quick-order', QuickOrderController::class)
        ->middleware('feature:quick_order')
        ->middleware(['auth:sanctum', 'role:stylist,admin']);
    Route::get('product-collections', [ProductCollectionController::class, 'index']);
    Route::get('products/featured', [ProductController::class, 'featured']);
    Route::get('products/search', [ProductController::class, 'search']);
    Route::get('products/{product}/related', [ProductController::class, 'related']);
    Route::get('products/{product}', [ProductController::class, 'show']);
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('brands', [BrandController::class, 'index']);
    Route::get('hair-profile-options', HairProfileOptionController::class);
    Route::post('coupons/validate', [CouponController::class, 'validate']);
    Route::post('recommendations', RecommendationController::class)->middleware('feature:hair_quiz');
    Route::get('bundles', [BundleController::class, 'index'])->middleware('feature:bundles,empty');
    Route::get('features', \App\Http\Controllers\Api\V1\FeatureController::class);
    Route::get('business', [\App\Http\Controllers\Api\V1\Admin\AdminBusinessController::class, 'publicProfile']);

    // One-click unsubscribe links in emails (signed, no login).
    Route::middleware('signed')->group(function () {
        Route::get('email/unsubscribe/{user}', [\App\Http\Controllers\Api\V1\EmailSubscriptionController::class, 'unsubscribe'])->name('email.unsubscribe');
        Route::post('email/unsubscribe/{user}', [\App\Http\Controllers\Api\V1\EmailSubscriptionController::class, 'oneClick'])->name('email.unsubscribe.one-click');
        Route::get('email/resubscribe/{user}', [\App\Http\Controllers\Api\V1\EmailSubscriptionController::class, 'resubscribe'])->name('email.resubscribe');
    });
    Route::get('content-blocks', [ContentBlockController::class, 'index']);
    // Candidate files are review-only assets. <img> cannot send the admin bearer
    // token, so the protected candidate list hands out short-lived signed URLs.
    Route::get('admin/catalog-image-candidates/{productId}/preview', [CatalogImageCandidateController::class, 'preview'])
        ->middleware('signed')
        ->name('admin.catalog-image-candidates.preview');
    Route::post('/checkout', [CheckoutController::class, 'checkout']);
    Route::post('/cart/quote', CartQuoteController::class);

    Route::middleware('auth:sanctum')->group(function () {

        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
        Route::put('/auth/password', [AuthController::class, 'changePassword']);

        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{order}', [OrderController::class, 'show']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);

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
            Route::get('/stylist-invitations', [StylistInvitationController::class, 'index'])->middleware('feature:stylist_invitations');
            Route::post('/stylist-invitations', [StylistInvitationController::class, 'create'])->middleware('feature:stylist_invitations');
            Route::post('/stylist-invitations/{id}/reissue', [StylistInvitationController::class, 'reissue'])->middleware('feature:stylist_invitations');
            Route::post('/stylist-invitations/{id}/revoke', [StylistInvitationController::class, 'revoke'])->middleware('feature:stylist_invitations');
            
            // Product Management
            Route::get('/products', [AdminProductController::class, 'index']);
            Route::get('/product-families', [AdminProductController::class, 'families']);
            Route::post('/products', [AdminProductController::class, 'store']);
            Route::put('/products/{id}', [AdminProductController::class, 'update']);
            Route::delete('/products/{id}', [AdminProductController::class, 'destroy']);
            Route::put('/products/{id}/stock', [AdminProductController::class, 'updateStock']);
            Route::post('/products/bulk-update', [AdminProductController::class, 'bulkUpdate']);
            Route::get('/catalogue-review', [CatalogueReviewController::class, 'index'])->middleware('feature:supplier_list');
            Route::post('/catalogue-review/{itemKey}/confirm-match', [CatalogueReviewController::class, 'confirmMatch'])->middleware('feature:supplier_list');
            Route::post('/catalogue-review/{itemKey}/create-product', [CatalogueReviewController::class, 'createMissing'])->middleware('feature:supplier_list');
            Route::post('/catalogue-review/{itemKey}/dismiss', [CatalogueReviewController::class, 'dismiss'])->middleware('feature:supplier_list');
            Route::get('/catalog-image-candidates', [CatalogImageCandidateController::class, 'index'])->middleware('feature:image_review');
            Route::get('/catalog-image-health', [CatalogImageCandidateController::class, 'health'])->middleware('feature:image_review');
            Route::get('/products/{product}/images', [\App\Http\Controllers\Api\V1\Admin\ProductImageController::class, 'index'])->middleware('feature:image_review');
            Route::post('/products/{product}/images', [\App\Http\Controllers\Api\V1\Admin\ProductImageController::class, 'store'])->middleware('feature:image_review');
            Route::patch('/products/{product}/images/{image}', [\App\Http\Controllers\Api\V1\Admin\ProductImageController::class, 'update'])->middleware('feature:image_review');
            Route::post('/catalog-image-candidates/{productId}/approve', [CatalogImageCandidateController::class, 'approve'])->middleware('feature:image_review');
            Route::get('/hair-quiz/products', [HairQuizController::class, 'index'])->middleware('feature:hair_quiz');
            Route::put('/hair-quiz/products/{product}', [HairQuizController::class, 'update'])->middleware('feature:hair_quiz');
            Route::post('/hair-quiz/preview', [HairQuizController::class, 'preview'])->middleware('feature:hair_quiz');
            Route::get('/content-blocks', [AdminContentBlockController::class, 'index'])->middleware('feature:content_blocks');
            Route::post('/content-blocks', [AdminContentBlockController::class, 'store'])->middleware('feature:content_blocks');
            Route::put('/content-blocks/{contentBlock}', [AdminContentBlockController::class, 'update'])->middleware('feature:content_blocks');
            Route::delete('/content-blocks/{contentBlock}', [AdminContentBlockController::class, 'destroy'])->middleware('feature:content_blocks');

            Route::get('/bundles', [AdminBundleController::class, 'index'])->middleware('feature:bundles');
            Route::post('/bundles', [AdminBundleController::class, 'store'])->middleware('feature:bundles');
            Route::put('/bundles/{bundle}', [AdminBundleController::class, 'update'])->middleware('feature:bundles');
            Route::delete('/bundles/{bundle}', [AdminBundleController::class, 'destroy'])->middleware('feature:bundles');
            Route::post('/bundles/simulate', [AdminPricingController::class, 'simulateOffer'])->middleware('feature:bundles');
            Route::get('/pricing-settings', [AdminPricingController::class, 'settings']);
            Route::put('/pricing-settings', [AdminPricingController::class, 'updateSettings']);
            
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
            Route::middleware('feature:order_delete')->group(function () {
                Route::delete('/orders/{id}', [\App\Http\Controllers\Api\V1\Admin\AdminOrderTrashController::class, 'trash'])->whereNumber('id');
                Route::post('/orders/{id}/restore', [\App\Http\Controllers\Api\V1\Admin\AdminOrderTrashController::class, 'restore'])->whereNumber('id');
                Route::delete('/orders/{id}/force', [\App\Http\Controllers\Api\V1\Admin\AdminOrderTrashController::class, 'forceDelete'])->whereNumber('id');
            });

            // Excel / CSV import and export of products (PRO plan)
            Route::prefix('product-import')->middleware('feature:excel_import')->controller(\App\Http\Controllers\Api\V1\Admin\AdminProductImportController::class)->group(function () {
                Route::get('/export', 'export');
                Route::post('/', 'upload');
                Route::post('/{token}/preview', 'preview')->whereUuid('token');
                Route::post('/{token}/apply', 'apply')->whereUuid('token');
                Route::post('/{token}/undo', 'undo')->whereUuid('token');
            });

            // PDF documents: order summary, delivery notes, price list (PRO plan)
            require __DIR__ . '/documents.php';

            // Customers (simple CRM): accounts and guest buyers, notes and tags
            Route::get('/customers', [\App\Http\Controllers\Api\V1\Admin\AdminCustomerController::class, 'index']);
            Route::get('/customers/{key}', [\App\Http\Controllers\Api\V1\Admin\AdminCustomerController::class, 'show'])->where('key', '[^/]+');
            Route::put('/customers/{key}', [\App\Http\Controllers\Api\V1\Admin\AdminCustomerController::class, 'update'])->where('key', '[^/]+');

            // Switchable features and the shop's identity
            Route::get('/features', [\App\Http\Controllers\Api\V1\Admin\AdminFeatureController::class, 'index']);
            Route::get('/ai-tokens', [\App\Http\Controllers\Api\V1\Admin\AdminAiTokenController::class, 'index']);
            Route::post('/ai-tokens', [\App\Http\Controllers\Api\V1\Admin\AdminAiTokenController::class, 'store'])->middleware('feature:mcp');
            Route::delete('/ai-tokens/{id}', [\App\Http\Controllers\Api\V1\Admin\AdminAiTokenController::class, 'destroy'])->whereNumber('id');
            Route::put('/features', [\App\Http\Controllers\Api\V1\Admin\AdminFeatureController::class, 'update']);
            Route::get('/business', [\App\Http\Controllers\Api\V1\Admin\AdminBusinessController::class, 'show']);
            Route::put('/business', [\App\Http\Controllers\Api\V1\Admin\AdminBusinessController::class, 'update']);
            Route::post('/business/logo', [\App\Http\Controllers\Api\V1\Admin\AdminBusinessController::class, 'uploadLogo']);

            // Viber & WhatsApp: templates, send-to-a-list, optional automatic WhatsApp (off by default).
            Route::prefix('messaging')->middleware('feature:messaging')->controller(\App\Http\Controllers\Api\V1\Admin\AdminMessagingController::class)->group(function () {
                Route::get('/templates', 'templates');
                Route::put('/templates', 'saveTemplates');
                Route::post('/audience', 'audience');
                Route::get('/whatsapp', 'whatsapp')->middleware('feature:'.\App\Services\Messaging\MessagingSettings::PREMIUM_FEATURE);
                Route::put('/whatsapp', 'saveWhatsApp')->middleware('feature:'.\App\Services\Messaging\MessagingSettings::PREMIUM_FEATURE);
                Route::post('/whatsapp/test', 'testWhatsApp')->middleware('feature:'.\App\Services\Messaging\MessagingSettings::PREMIUM_FEATURE);
            });

            // Email: mailbox settings are always reachable; writing emails and the outbox can be switched off.
            Route::get('/email-settings', [\App\Http\Controllers\Api\V1\Admin\AdminEmailController::class, 'settings']);
            Route::put('/email-settings', [\App\Http\Controllers\Api\V1\Admin\AdminEmailController::class, 'updateSettings']);
            Route::post('/email-settings/test', [\App\Http\Controllers\Api\V1\Admin\AdminEmailController::class, 'test']);
            Route::get('/email-settings/deliverability', [\App\Http\Controllers\Api\V1\Admin\AdminEmailController::class, 'deliverability']);
            Route::get('/email-templates', [\App\Http\Controllers\Api\V1\Admin\AdminEmailController::class, 'templates']);
            Route::get('/email-templates/{key}/preview', [\App\Http\Controllers\Api\V1\Admin\AdminEmailController::class, 'templatePreview']);
            Route::prefix('emails')->middleware('feature:admin_emails')->controller(\App\Http\Controllers\Api\V1\Admin\AdminEmailController::class)->group(function () {
                Route::get('/overview', 'overview');
                Route::get('/logs', 'logs');
                Route::get('/logs/{log}', 'showLog');
                Route::post('/logs/{log}/retry', 'retry');
                Route::delete('/logs/{log}', 'destroyLog');
                Route::post('/retry-failed', 'retryAll');
                Route::post('/import-legacy', 'importLegacy');
                Route::post('/audience', 'audience');
                Route::post('/preview', 'preview');
                Route::post('/send', 'send');
                Route::get('/campaigns', 'campaigns');
            });
            
            // Stylist Request Management
            Route::get('/stylist-requests', [StylistRequestAdminController::class, 'index']);
            Route::get('/stylist-requests/{id}', [StylistRequestAdminController::class, 'show']);
            Route::post('/stylist-requests/{id}/approve', [StylistRequestAdminController::class, 'approve']);
            Route::post('/stylist-requests/{id}/reject', [StylistRequestAdminController::class, 'reject']);
            
            // Reports & Analytics
            Route::get('/reports/sales', [AdminReportController::class, 'sales']);
            Route::get('/reports/products', [AdminReportController::class, 'products']);
            Route::get('/reports/overview', [AdminReportController::class, 'overview'])->middleware('feature:sales_reports');
            Route::get('/reports/summary', [AdminReportController::class, 'summary'])->middleware('feature:sales_reports');
            Route::get('/reports/slow-movers', [AdminReportController::class, 'slowMovers'])->middleware('feature:sales_reports');
            
            // Stylist referral codes and store credit
            Route::get('/referrals', [AdminReferralController::class, 'index'])->middleware('feature:referrals');
            Route::post('/referrals', [AdminReferralController::class, 'store'])->middleware('feature:referrals');
            Route::put('/referrals/settings', [AdminReferralController::class, 'updateSettings'])->middleware('feature:referrals');
            Route::put('/referrals/{coupon}', [AdminReferralController::class, 'update'])->middleware('feature:referrals');
            Route::post('/users/{user}/store-credit', [AdminReferralController::class, 'adjustCredit'])->middleware('feature:referrals');

            // Distributor Codes
            Route::get('/distributor-codes', [AdminDistributorController::class, 'index'])->middleware('feature:distributors');
            Route::get('/distributor-codes/stats', [AdminDistributorController::class, 'stats'])->middleware('feature:distributors');
        });
        
        // Stylist Routes
        Route::prefix('stylist')->middleware('role:stylist,admin')->group(function () {
            // Dashboard
            Route::get('/dashboard', [StylistDashboardController::class, 'index']);
            
            // Distributor Codes
            Route::get('/codes', [StylistCodeController::class, 'index']);
            Route::post('/codes/generate', [StylistCodeController::class, 'generate']);
            Route::get('/codes/{code}/stats', [StylistCodeController::class, 'stats']);
            Route::put('/codes/{code}', [StylistCodeController::class, 'update']);
            
            // Orders
            Route::get('/orders', [StylistOrderController::class, 'index']);

            // Referral code for clients and the store credit it earns
            Route::get('/referral', [StylistReferralController::class, 'show'])->middleware('feature:referrals');
            Route::put('/referral', [StylistReferralController::class, 'update'])->middleware('feature:referrals');
        });
    });
});
