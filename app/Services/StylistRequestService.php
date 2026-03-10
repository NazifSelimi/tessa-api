<?php

namespace App\Services;

use App\Models\RequestStylist;
use App\Models\User;

class StylistRequestService
{
    /**
     * List stylist requests with optional search filter and pagination.
     */
    public function listFiltered(array $filters, int $perPage = 20)
    {
        $query = RequestStylist::with('user');

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                  ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
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
        return [
            'id' => $req->id,
            'userId' => $req->user_id,
            'userName' => $user ? trim($user->first_name . ' ' . $user->last_name) : 'Unknown',
            'userEmail' => $user?->email,
            'saloonName' => $req->saloon_name,
            'saloonCity' => $req->saloon_city,
            'saloonAddress' => $req->saloon_address,
            'saloonPhone' => $req->saloon_phone,
            'message' => $req->message,
            'isApproved' => $user ? $user->role === User::ROLE_STYLIST : false,
            'createdAt' => $req->created_at?->toISOString(),
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

        // Create or update stylist profile from request data
        $stylistRequest->user->stylistProfile()->updateOrCreate(
            ['user_id' => $stylistRequest->user_id],
            [
                'saloon_name' => $stylistRequest->saloon_name,
                'saloon_address' => $stylistRequest->saloon_address,
                'saloon_city' => $stylistRequest->saloon_city,
                'saloon_phone' => $stylistRequest->saloon_phone,
            ]
        );

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
     * Reject a stylist request — reverts role if needed and deletes the request.
     */
    public function reject($id): void
    {
        $stylistRequest = RequestStylist::findOrFail($id);

        // If user was already approved, revert role (explicit assignment)
        if ($stylistRequest->user && $stylistRequest->user->role === User::ROLE_STYLIST) {
            $stylistRequest->user->role = User::ROLE_USER;
            $stylistRequest->user->save();
        }

        $stylistRequest->delete();
    }

    /**
     * Submit a new stylist request (public endpoint).
     */
    public function submitRequest(User $user, array $validated): array
    {
        // Check if user already submitted a request
        if ($user->request_submitted) {
            return ['created' => false, 'error' => 'You already have a stylist request submitted', 'code' => 422];
        }

        $existingRequest = RequestStylist::where('user_id', $user->id)->first();
        if ($existingRequest) {
            return ['created' => false, 'error' => 'You already have a stylist request', 'code' => 422];
        }

        $stylistRequest = RequestStylist::create([
            'user_id' => $user->id,
            'saloon_name' => $validated['saloon_name'],
            'saloon_city' => $validated['saloon_city'],
            'saloon_address' => $validated['saloon_address'],
            'saloon_phone' => $validated['saloon_phone'],
            'message' => $validated['message'] ?? null,
        ]);

        // Mark user as having submitted a request
        $user->update(['request_submitted' => true]);

        return [
            'created' => true,
            'data' => [
                'id' => (string) $stylistRequest->id,
                'userId' => (string) $stylistRequest->user_id,
                'saloonName' => $stylistRequest->saloon_name,
                'saloonCity' => $stylistRequest->saloon_city,
                'saloonAddress' => $stylistRequest->saloon_address,
                'saloonPhone' => $stylistRequest->saloon_phone,
                'status' => 'pending',
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
            'status' => $user->isStylist() ? 'approved' : 'pending',
            'createdAt' => $stylistRequest->created_at?->toISOString(),
        ];
    }
}
