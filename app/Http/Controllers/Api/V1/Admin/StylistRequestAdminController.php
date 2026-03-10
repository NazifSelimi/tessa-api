<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\StylistRequestService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class StylistRequestAdminController extends Controller
{
    public function __construct(
        protected StylistRequestService $stylistRequestService
    ) {}

    /**
     * List all stylist requests with pagination.
     */
    public function index(Request $request)
    {
        $filters = $request->only(['search']);
        $perPage = $request->per_page ?? 20;

        $requests = $this->stylistRequestService->listFiltered($filters, $perPage);

        $data = $requests->getCollection()->map(
            fn ($req) => $this->stylistRequestService->mapToResponse($req)
        );

        return ApiResponse::ok(
            $data,
            200,
            [
                'current_page' => $requests->currentPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
                'last_page' => $requests->lastPage(),
            ]
        );
    }

    /**
     * Approve a stylist request — upgrades user role to ROLE_STYLIST
     * and creates a stylist_profiles entry from request data.
     */
    public function approve(Request $request, $id)
    {
        $result = $this->stylistRequestService->approve($id);

        if (!$result['approved']) {
            return ApiResponse::error($result['error'], $result['code']);
        }

        return ApiResponse::ok(
            $result['data'],
            200,
            [],
            'Stylist request approved successfully'
        );
    }

    /**
     * Reject a stylist request — no DB status column exists,
     * so we just delete the request.
     */
    public function reject(Request $request, $id)
    {
        $this->stylistRequestService->reject($id);

        return ApiResponse::ok(null, 200, [], 'Stylist request rejected');
    }
}
