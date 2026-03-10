<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $roleValue = $this->role;
        if (is_numeric($roleValue)) {
            $roleValue = match ((int) $roleValue) {
                \App\Models\User::ROLE_ADMIN => 'admin',
                \App\Models\User::ROLE_STYLIST => 'stylist',
                default => 'user',
            };
        }

        return [
            'id' => (string) $this->id,
            'firstName' => $this->first_name,
            'lastName' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $roleValue,
            'isStylist' => (int) $this->role === \App\Models\User::ROLE_STYLIST,
            'address' => $this->address,
            'city' => $this->city,
            'postcode' => $this->postcode,
            'requestSubmitted' => (bool) $this->request_submitted,
            'totalOrders' => $this->when(isset($this->orders_count), $this->orders_count),
            'totalSpent' => $this->when(isset($this->total_spent), number_format((float) $this->total_spent, 2)),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
