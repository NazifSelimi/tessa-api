<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DistributorCodeResource;
use App\Models\DistributorCode;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminDistributorController extends Controller
{
    /**
     * List all distributor / invitation codes with creator info.
     */
    public function index(Request $request)
    {
        $query = DistributorCode::with(['creator', 'usedBy'])->latest();

        // Optional search by code or creator name/email
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                  ->orWhereHas('creator', function ($q2) use ($search) {
                      $q2->where('first_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        // Optional filter: used / unused
        if ($request->has('used')) {
            $query->where('used', filter_var($request->query('used'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = (int) $request->query('per_page', 20);
        $codes = $query->paginate($perPage);

        return ApiResponse::ok(
            DistributorCodeResource::collection($codes)->response()->getData(true)
        );
    }

    /**
     * Summary stats for all distributor codes.
     */
    public function stats()
    {
        $total = DistributorCode::count();
        $used  = DistributorCode::where('used', true)->count();
        $active = DistributorCode::where('used', false)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->count();

        return ApiResponse::ok([
            'totalCodes'  => $total,
            'usedCodes'   => $used,
            'activeCodes' => $active,
        ]);
    }
}
