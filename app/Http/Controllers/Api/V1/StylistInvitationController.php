<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ActivateStylistInvitationRequest;
use App\Http\Resources\UserResource;
use App\Services\StylistInvitationService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class StylistInvitationController extends Controller
{
    public function __construct(private readonly StylistInvitationService $invitations) {}

    public function show(string $token)
    {
        $invitation = $this->invitations->resolve($token);

        if (! $invitation) {
            return ApiResponse::error('This activation link is invalid.', 404);
        }

        if (! $invitation->isAvailable()) {
            return ApiResponse::error('This activation link has expired or has already been used.', 410);
        }

        return ApiResponse::ok([
            'display_name' => $invitation->display_name,
            'email' => $invitation->email,
            'phone' => $invitation->phone,
            'address' => $invitation->address,
            'city' => $invitation->city,
            'postal_code' => $invitation->postal_code,
            'business_name' => $invitation->business_name,
            'business_address' => $invitation->business_address,
            'business_city' => $invitation->business_city,
            'business_phone' => $invitation->business_phone,
            'missing_fields' => $this->invitations->missingFields($invitation),
            'expires_at' => $invitation->expires_at->toISOString(),
        ]);
    }

    public function activate(ActivateStylistInvitationRequest $request, string $token)
    {
        $invitation = $this->invitations->resolve($token);

        if (! $invitation) {
            return ApiResponse::error('This activation link is invalid.', 404);
        }

        if (! $invitation->isAvailable()) {
            return ApiResponse::error('This activation link has expired or has already been used.', 410);
        }

        $user = $this->invitations->activate($invitation, $request->validated());

        return ApiResponse::ok([
            'token' => $user->createToken('stylist-activation')->plainTextToken,
            'user' => new UserResource($user),
        ], 201, [], 'Stylist account activated successfully.');
    }

    public function create(Request $request)
    {
        $data = $request->validate([
            'source_reference' => ['nullable', 'string', 'max:100'],
            'display_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'business_name' => ['nullable', 'string', 'max:255'],
            'business_address' => ['nullable', 'string', 'max:255'],
            'business_city' => ['nullable', 'string', 'max:100'],
            'business_phone' => ['nullable', 'string', 'max:30'],
        ]);

        [$invitation, $token] = $this->invitations->create($data);

        return ApiResponse::ok([
            'id' => (string) $invitation->id,
            'activation_url' => rtrim(config('app.frontend_url', config('app.url')), '/') . '/stylist/activate/' . $token,
            'expires_at' => $invitation->expires_at->toISOString(),
        ], 201, [], 'Stylist activation link created successfully.');
    }
}
