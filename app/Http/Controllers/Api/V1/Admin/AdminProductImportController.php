<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ProductImport\ColumnMapper;
use App\Services\ProductImport\ProductImporter;
use App\Services\ProductImport\SheetReader;
use App\Services\ProductImport\ValueParser;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Excel / CSV import and export of products (prices, stock, new products). */
class AdminProductImportController extends Controller
{
    public function __construct(private ProductImporter $importer, private SheetReader $reader) {}

    /** Step 1: upload a file and see what it would change. Nothing is saved. */
    public function upload(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'max:10240']]);
        $file = $request->file('file');

        try {
            $sheet = $this->reader->read($file->getRealPath(), strtolower($file->getClientOriginalExtension()));
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
        if (count($sheet['rows']) < 2) {
            return ApiResponse::error('The file has no rows under the headings.', 422);
        }

        $token = $this->importer->store($sheet, $file->getClientOriginalName());

        return ApiResponse::ok(['token' => $token, 'filename' => $file->getClientOriginalName(), ...$this->shorten($this->importer->plan($sheet['rows']))]);
    }

    /** Step 2 (optional): the admin changed the columns or options. */
    public function preview(Request $request, string $token)
    {
        try {
            return ApiResponse::ok(['token' => $token, ...$this->shorten($this->plan($request, $token))]);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /** Unchanged rows are only counted; the screen lists what would happen. */
    private function shorten(array $plan): array
    {
        $shown = array_values(array_filter($plan['lines'], fn (array $line) => $line['action'] !== 'same'));

        return [...$plan, 'lines' => array_slice($shown, 0, ProductImporter::PREVIEW_ROWS), 'linesHidden' => max(0, count($shown) - ProductImporter::PREVIEW_ROWS)];
    }

    /** Step 3: save the changes. */
    public function apply(Request $request, string $token)
    {
        try {
            $plan = $this->plan($request, $token);
            if ($plan['errors']) {
                return ApiResponse::error($plan['errors'][0], 422);
            }

            return ApiResponse::ok([...$this->importer->apply($token, $plan), 'skipped' => $plan['summary']['problems'], 'notCreated' => $plan['summary']['new']]);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    public function undo(string $token)
    {
        try {
            return ApiResponse::ok($this->importer->undo($token));
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /** The whole catalogue as an Excel file that can be edited and imported back. */
    public function export(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle('Products');
        $fields = ['id', 'manufacturer_code', 'name', 'brand', 'category', 'price', 'stylist_price', 'cost_price', 'quantity', 'visibility'];
        $sheet->fromArray(array_map(fn (string $field) => ColumnMapper::FIELDS[$field], $fields), null, 'A1');

        $line = 2;
        Product::query()->with(['brand:id,name', 'category:id,name'])->orderBy('id')->chunk(500, function ($products) use ($sheet, &$line) {
            foreach ($products as $product) {
                $sheet->fromArray([
                    $product->id,
                    null,
                    $product->name,
                    $product->brand?->name,
                    $product->category?->name,
                    (float) $product->price,
                    (float) $product->stylist_price,
                    $product->cost_price === null ? null : (float) $product->cost_price,
                    (int) $product->quantity,
                    ValueParser::visibilityLabel(array_values(array_filter(Product::AUDIENCES, fn (string $audience) => (bool) $product->{'visible_to_' . $audience}))),
                ], null, 'A' . $line);
                // Article codes may start with 0: keep them as text.
                $sheet->setCellValueExplicit('B' . $line, (string) $product->manufacturer_code, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $line++;
            }
        });
        $sheet->getStyle('A1:J1')->getFont()->setBold(true);
        $sheet->freezePane('A2');
        foreach (['A' => 8, 'B' => 14, 'C' => 48, 'D' => 18, 'E' => 20, 'F' => 13, 'G' => 14, 'H' => 12, 'I' => 8, 'J' => 28] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        return response()->streamDownload(fn () => (new Xlsx($spreadsheet))->save('php://output'), 'products-' . now()->format('Y-m-d') . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function plan(Request $request, string $token): array
    {
        $validated = $request->validate([
            'mapping' => ['nullable', 'array'],
            'mapping.*' => ['nullable', 'integer', 'min:0'],
            'create_new' => ['nullable', 'boolean'],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['string'],
        ]);
        $stored = $this->importer->load($token);
        $mapping = isset($validated['mapping']) ? array_filter($validated['mapping'], fn ($column) => $column !== null) : null;

        return $this->importer->plan($stored['rows'], $mapping, ['create_new' => (bool) ($validated['create_new'] ?? false), 'fields' => $validated['fields'] ?? null]);
    }
}
