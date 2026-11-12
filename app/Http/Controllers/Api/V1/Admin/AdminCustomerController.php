<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\CustomerDirectory;
use App\Support\ApiResponse;
use App\Support\Customers\CustomerCsv;
use App\Support\Customers\CustomerKey;
use App\Support\Customers\CustomerQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin Customers page (simple CRM): who buys, how much, when they last
 * ordered, notes and tags. Accounts and guest buyers in one list.
 */
class AdminCustomerController extends Controller
{
    public function __construct(private CustomerDirectory $directory) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(['customer', 'business', 'guest', 'registered'])],
            'city' => ['nullable', 'string', 'max:120'],
            'tag' => ['nullable', 'string', 'max:40'],
            'min_spent' => ['nullable', 'numeric', 'min:0'],
            'max_spent' => ['nullable', 'numeric', 'min:0'],
            'min_orders' => ['nullable', 'integer', 'min:0'],
            'has_orders' => ['nullable', 'boolean'],
            'last_order_after' => ['nullable', 'date_format:Y-m-d'],
            'last_order_before' => ['nullable', 'date_format:Y-m-d'],
            'inactive_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'new_since' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['nullable', Rule::in(CustomerQuery::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'format' => ['nullable', Rule::in(['json', 'csv'])],
        ]);

        $result = $this->directory->search($data, $data['sort'] ?? 'last_order_at', $data['direction'] ?? 'desc');

        if (($data['format'] ?? 'json') === 'csv') {
            return response(CustomerCsv::make($result['rows']), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="customers-' . now()->format('Y-m-d') . '.csv"',
                'Cache-Control' => 'no-store',
            ]);
        }

        $perPage = (int) ($data['per_page'] ?? 25);
        $lastPage = max(1, (int) ceil($result['total'] / $perPage));
        $page = min((int) ($data['page'] ?? 1), $lastPage);
        $rows = array_slice($result['rows'], ($page - 1) * $perPage, $perPage);

        return ApiResponse::ok(array_map([CustomerDirectory::class, 'publicRow'], $rows), 200, [
            'currentPage' => $page,
            'lastPage' => $lastPage,
            'perPage' => $perPage,
            'total' => $result['total'],
            'summary' => $result['summary'],
            'tags' => CustomerQuery::tags($result['all']),
            'cities' => CustomerQuery::cities($result['all']),
        ]);
    }

    public function show(string $key)
    {
        $row = $this->findOrFail($key);

        return $row instanceof \Illuminate\Http\JsonResponse ? $row : ApiResponse::ok($this->directory->profile($row));
    }

    public function update(Request $request, string $key)
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:5000'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
        ]);
        $row = $this->findOrFail($key);
        if ($row instanceof \Illuminate\Http\JsonResponse) {
            return $row;
        }

        $saved = $this->directory->saveNote($row, $data['notes'] ?? null, $data['tags'] ?? [], $request->user());

        return ApiResponse::ok(CustomerDirectory::publicRow($saved), 200, null, 'Saved');
    }

    private function findOrFail(string $key): array|\Illuminate\Http\JsonResponse
    {
        $key = rawurldecode($key);
        if (! CustomerKey::isValid(strtolower($key))) {
            return ApiResponse::error('Customer not found', 404);
        }

        return $this->directory->find($key) ?? ApiResponse::error('Customer not found', 404);
    }
}
