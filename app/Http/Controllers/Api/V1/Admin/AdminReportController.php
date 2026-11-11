<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use App\Services\SalesReportService;
use App\Support\Reports\ReportPeriod;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminReportController extends Controller
{
    public function __construct(
        protected ReportService $reportService,
        protected SalesReportService $salesReports,
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

    /** Everything on the Sales Reports page for one period, with the previous period to compare. */
    public function overview(Request $request)
    {
        return ApiResponse::ok($this->salesReports->overview($this->period($request)));
    }

    /** Headline figures only (the dashboard's "this month vs last month" card). */
    public function summary(Request $request)
    {
        return ApiResponse::ok($this->salesReports->summary($this->period($request)));
    }

    /** In-stock products that have not sold for N days. */
    public function slowMovers(Request $request)
    {
        $validated = $request->validate(['days' => ['nullable', 'integer', 'min:7', 'max:730']]);

        return ApiResponse::ok($this->salesReports->slowMovers((int) ($validated['days'] ?? 60)));
    }

    private function period(Request $request): ReportPeriod
    {
        $validated = $request->validate([
            'period' => ['nullable', 'in:' . implode(',', ReportPeriod::PRESETS)],
            'from' => ['required_if:period,custom', 'nullable', 'date'],
            'to' => ['required_if:period,custom', 'nullable', 'date', 'after_or_equal:from'],
            'group_by' => ['nullable', 'in:day,week,month'],
        ]);

        return ReportPeriod::resolve(
            $validated['period'] ?? 'this_month',
            $validated['from'] ?? null,
            $validated['to'] ?? null,
            null,
            $validated['group_by'] ?? null,
        );
    }
}
