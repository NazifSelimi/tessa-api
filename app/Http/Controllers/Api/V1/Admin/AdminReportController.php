<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminReportController extends Controller
{
    public function __construct(
        protected ReportService $reportService
    ) {}

    /**
     * Get sales reports with date ranges.
     */
    public function sales(Request $request)
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'group_by' => ['nullable', 'in:day,week,month'],
        ]);

        $data = $this->reportService->getSalesReport(
            $validated['start_date'],
            $validated['end_date'],
            $validated['group_by'] ?? 'day'
        );

        return ApiResponse::ok($data);
    }

    /**
     * Get product performance analytics.
     */
    public function products(Request $request)
    {
        return ApiResponse::ok($this->reportService->getProductReport());
    }
}

