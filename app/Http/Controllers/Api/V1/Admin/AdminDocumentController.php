<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Documents\DocumentShop;
use App\Services\Documents\DocumentText;
use App\Services\Documents\OrderDocumentData;
use App\Services\Documents\PdfRenderer;
use App\Services\Documents\PriceListData;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** PDF downloads for admins: order summary, delivery notes, price list. */
class AdminDocumentController extends Controller
{
    public function __construct(private PdfRenderer $pdf, private DocumentShop $shop) {}

    /** One order as an order summary (pro-forma) or a delivery note. */
    public function order(Request $request, int $id, string $type): Response
    {
        $order = Order::with(['items.product', 'info', 'user'])->findOrFail($id);
        $locale = $this->locale($request, $order);
        $view = $type === 'invoice' ? 'documents.invoice' : 'documents.delivery-note';

        return $this->download(
            $this->pdf->render($view, ['orders' => [OrderDocumentData::for($order, $locale)], 'shop' => $this->shop, 'locale' => $locale]),
            ($type === 'invoice' ? 'order-' : 'delivery-note-') . $order->id . '.pdf',
        );
    }

    /** Delivery notes for many orders in one PDF, one order per page. */
    public function deliveryNotes(Request $request): Response
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
            'locale' => ['nullable', Rule::in(DocumentText::LOCALES)],
        ]);

        $orders = Order::with(['items.product', 'info', 'user'])->whereIn('id', $validated['ids'])->orderBy('id')->get();
        abort_if($orders->isEmpty(), 404);

        $data = $orders->map(fn (Order $order) => OrderDocumentData::for($order, $this->locale($request, $order)))->all();

        return $this->download(
            $this->pdf->render('documents.delivery-note', ['orders' => $data, 'shop' => $this->shop, 'locale' => $data[0]['locale']]),
            'delivery-notes-' . now()->format('Y-m-d') . '.pdf',
        );
    }

    public function priceListOptions()
    {
        return ApiResponse::ok(PriceListData::options());
    }

    public function priceList(Request $request): Response
    {
        $validated = $request->validate([
            'audience' => ['required', Rule::in(PriceListData::AUDIENCES)],
            'brand_ids' => ['nullable', 'array'],
            'brand_ids.*' => ['integer'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer'],
            'in_stock_only' => ['nullable', 'boolean'],
            'locale' => ['nullable', Rule::in(DocumentText::LOCALES)],
        ]);
        $locale = DocumentText::normalise($validated['locale'] ?? null, $this->shop->defaultLocale());

        return $this->download(
            $this->pdf->render('documents.price-list', [
                'list' => PriceListData::build($validated, $locale),
                'audience' => $validated['audience'],
                'inStockOnly' => (bool) ($validated['in_stock_only'] ?? false),
                'shop' => $this->shop,
                'locale' => $locale,
            ]),
            'price-list-' . $validated['audience'] . '-' . now()->format('Y-m-d') . '.pdf',
        );
    }

    /** ?locale= wins; otherwise the customer's own language, then the shop's. */
    private function locale(Request $request, Order $order): string
    {
        $asked = $request->input('locale');

        return $asked
            ? DocumentText::normalise($asked, $this->shop->defaultLocale())
            : DocumentText::localeFor($order->user, $this->shop->defaultLocale());
    }

    private function download(string $bytes, string $filename): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
