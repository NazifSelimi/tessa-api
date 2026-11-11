<?php

use App\Http\Controllers\Api\V1\Admin\AdminDocumentController;
use Illuminate\Support\Facades\Route;

/*
| PDF documents (PRO plan feature "pdf_documents"). Included from the admin
| group in routes/api.php, so every route here is already admin-only.
*/

Route::middleware('feature:pdf_documents')->group(function () {
    Route::get('/orders/{id}/documents/{type}', [AdminDocumentController::class, 'order'])
        ->whereNumber('id')
        ->whereIn('type', ['invoice', 'delivery-note']);
    Route::post('/orders/delivery-notes', [AdminDocumentController::class, 'deliveryNotes']);
    Route::get('/price-list/options', [AdminDocumentController::class, 'priceListOptions']);
    Route::post('/price-list/pdf', [AdminDocumentController::class, 'priceList']);
});
