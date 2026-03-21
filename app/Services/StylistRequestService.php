<?php

namespace App\Services;

use App\Models\RequestStylist;
use App\Models\User;
use App\Events\StylistRequestApproved;
use App\Events\StylistRequestRejected;
use App\Events\StylistRequestSubmitted;
use Illuminate\Support\Facades\Log;

class StylistRequestService
{
    /**
     * List stylist requests with optional search filter and pagination.
     */
    public function listFiltered(array $filters, int $perPage = 20)
    {
        $query = RequestStylist::with('user');

        // Filter by approval status
        if (!empty($filters['status'])) {
            $status = $filters['status'];
            $query->where('status', $status);
        }

        // Search by user name, email, or salon name/city
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('saloon_name', 'like', "%{$search}%")
                  ->orWhere('saloon_city', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('email', 'like', "%{$search}%")
                         ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
                  });
            });
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * Map a stylist request record to an API-ready array.
     */
    public function mapToResponse($req): array
    {
        $user = $req->user;
        $status = $req->status ?: RequestStylist::STATUS_PENDING;
        $isApproved = $status === RequestStylist::STATUS_APPROVED;

        return [
            'id' => (string) $req->id,
            'userId' => (string) $req->user_id,
            'userName' => $user ? trim($user->first_name . ' ' . $user->last_name) : 'Unknown',
            'userEmail' => $user?->email,
            'saloonName' => $req->saloon_name,
            'saloonAddress' => $req->saloon_address,
            'saloonCity' => $req->saloon_city,
            'saloonPhone' => $req->saloon_phone,
            'message' => $req->message,
            'isApproved' => $isApproved,
            'status' => $status,
            'rejectionReason' => $req->rejection_reason,
            'createdAt' => $req->created_at?->toISOString(),
            'updatedAt' => $req->updated_at?->toISOString(),
        ];
    }

    /**
     * Approve a stylist request — upgrades user role and creates profile.
     */
    public function approve($id): array
    {
        $stylistRequest = RequestStylist::with('user')->findOrFail($id);

        if (!$stylistRequest->user) {
            return ['approved' => false, 'error' => 'Associated user not found', 'code' => 404];
        }

        if ($stylistRequest->user->role === User::ROLE_STYLIST) {
            return ['approved' => false, 'error' => 'User is already a stylist', 'code' => 422];
        }

        // Upgrade user role (explicit — role is not mass-assignable)
        $stylistRequest->user->role = User::ROLE_STYLIST;
        $stylistRequest->user->request_submitted = true;
        $stylistRequest->user->save();
        $stylistRequest->status = RequestStylist::STATUS_APPROVED;
        $stylistRequest->rejection_reason = null;
        $stylistRequest->save();

        // Create or update stylist profile from request data
        $stylistRequest->user->profile()->updateOrCreate(
            ['user_id' => $stylistRequest->user_id],
            [
                'saloon_name' => $stylistRequest->saloon_name,
                'saloon_address' => $stylistRequest->saloon_address,
                'saloon_city' => $stylistRequest->saloon_city,
                'saloon_phone' => $stylistRequest->saloon_phone,
            ]
        );

        // Dispatch event after successful approval so listeners can send emails/notifications
        try {
            StylistRequestApproved::dispatch($stylistRequest, $stylistRequest->user);
        } catch (\Throwable $e) {
            Log::error('Failed to dispatch StylistRequestApproved event', [
                'request_id' => $stylistRequest->id,
                'user_id' => $stylistRequest->user_id,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'approved' => true,
            'data' => [
                'id' => $stylistRequest->id,
                'userId' => $stylistRequest->user_id,
                'role' => User::ROLE_STYLIST,
            ],
        ];
    }

    /**
     * Reject a stylist request — reverts role if needed, emails the user, and deletes the request.
     */
    public function reject($id, ?string $reason = null): void
    {
        $stylistRequest = RequestStylist::with('user')->findOrFail($id);

        $user = $stylistRequest->user;
        // If user was already approved, revert role (explicit assignment)
        if ($user && $user->role === User::ROLE_STYLIST) {
            $user->role = User::ROLE_USER;
            $user->save();
        }

        $stylistRequest->status = RequestStylist::STATUS_REJECTED;
        $stylistRequest->rejection_reason = $reason;
        $stylistRequest->save();

        // Dispatch event after successful rejection so listeners can send emails/notifications
        if ($user) {
            try {
                StylistRequestRejected::dispatch($stylistRequest, $user, $reason);
            } catch (\Throwable $e) {
                Log::error('Failed to dispatch StylistRequestRejected event', [
                    'request_id' => $stylistRequest->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Submit a new stylist request (public endpoint).
     */
    public function submitRequest(User $user, array $validated): array
    {
        // Check if user already submitted a request
        $existingRequest = RequestStylist::where('user_id', $user->id)->latest()->first();

        if ($existingRequest && $existingRequest->status !== RequestStylist::STATUS_REJECTED) {
            return ['created' => false, 'error' => 'You already have a stylist request submitted', 'code' => 422];
        }

        $payload = [
            'saloon_name' => $validated['saloon_name'],
            'saloon_city' => $validated['saloon_city'],
            'saloon_address' => $validated['saloon_address'],
            'saloon_phone' => $validated['saloon_phone'],
            'message' => $validated['message'] ?? null,
            'status' => RequestStylist::STATUS_PENDING,
            'rejection_reason' => null,
        ];

        $stylistRequest = $existingRequest
            ? tap($existingRequest)->update($payload)
            : RequestStylist::create(['user_id' => $user->id, ...$payload]);

        // Mark user as having submitted a request
        $user->update(['request_submitted' => true]);
        
        // Dispatch event after successful creation so listeners can notify admins
        try {
            StylistRequestSubmitted::dispatch($stylistRequest, $user);
        } catch (\Throwable $e) {
            Log::error('Failed to dispatch StylistRequestSubmitted event', [
                'request_id' => $stylistRequest->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'created' => true,
            'data' => [
                'id' => (string) $stylistRequest->id,
                'userId' => (string) $stylistRequest->user_id,
                'saloonName' => $stylistRequest->saloon_name,
                'saloonCity' => $stylistRequest->saloon_city,
                'saloonAddress' => $stylistRequest->saloon_address,
                'saloonPhone' => $stylistRequest->saloon_phone,
                'status' => RequestStylist::STATUS_PENDING,
                'rejectionReason' => null,
                'createdAt' => $stylistRequest->created_at?->toISOString(),
            ],
        ];
    }

    /**
     * Get the status of a user's stylist request.
     */
    public function getRequestStatus(User $user): array
    {
        $stylistRequest = RequestStylist::where('user_id', $user->id)->latest()->first();

        if (!$stylistRequest) {
            return [
                'hasRequest' => false,
                'status' => null,
            ];
        }

        return [
            'hasRequest' => true,
            'id' => (string) $stylistRequest->id,
            'saloonName' => $stylistRequest->saloon_name,
            'saloonCity' => $stylistRequest->saloon_city,
            'saloonAddress' => $stylistRequest->saloon_address,
            'saloonPhone' => $stylistRequest->saloon_phone,
            'message' => $stylistRequest->message,
            'status' => $stylistRequest->status ?: RequestStylist::STATUS_PENDING,
            'rejectionReason' => $stylistRequest->rejection_reason,
            'createdAt' => $stylistRequest->created_at?->toISOString(),
            'updatedAt' => $stylistRequest->updated_at?->toISOString(),
        ];
    }
}
