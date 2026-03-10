<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserService
{
    /**
     * List users with optional filters and pagination.
     */
    public function listFiltered(array $filters, int $perPage = 20)
    {
        $query = User::query();

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (isset($filters['role']) && $filters['role'] !== '' && $filters['role'] !== null) {
            $roleMap = ['admin' => User::ROLE_ADMIN, 'stylist' => User::ROLE_STYLIST, 'user' => User::ROLE_USER];
            $roleValue = $roleMap[$filters['role']] ?? (int) $filters['role'];
            $query->where('role', $roleValue);
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * Find a user with order stats.
     */
    public function findWithOrderStats($id): User
    {
        $user = User::withCount('orders')
            ->with('orders')
            ->findOrFail($id);

        $user->total_spent = $user->orders->sum('total');

        return $user;
    }

    /**
     * Normalize camelCase fields and update a user.
     */
    public function updateUser($id, array $data): User
    {
        $user = User::findOrFail($id);

        // Map camelCase to snake_case
        if (isset($data['firstName']) && !isset($data['first_name'])) {
            $data['first_name'] = $data['firstName'];
        }
        if (isset($data['lastName']) && !isset($data['last_name'])) {
            $data['last_name'] = $data['lastName'];
        }

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        // Extract role and handle via explicit assignment (role is not mass-assignable)
        if (isset($data['role'])) {
            $roleValue = $data['role'];
            if (is_string($roleValue)) {
                $roleMap = ['admin' => User::ROLE_ADMIN, 'stylist' => User::ROLE_STYLIST, 'user' => User::ROLE_USER];
                $roleValue = $roleMap[$roleValue] ?? User::ROLE_USER;
            }
            $user->role = (int) $roleValue;
            unset($data['role']);
        }

        $user->fill($data);
        $user->save();

        return $user->fresh();
    }

    /**
     * Delete a user (with safety checks).
     */
    public function delete($id, $currentUserId): array
    {
        if ($currentUserId == $id) {
            return ['deleted' => false, 'error' => 'Cannot delete your own account'];
        }

        $user = User::findOrFail($id);

        if ($user->orders()->exists()) {
            return ['deleted' => false, 'error' => 'Cannot delete user with existing orders'];
        }

        $user->delete();

        return ['deleted' => true];
    }
}
