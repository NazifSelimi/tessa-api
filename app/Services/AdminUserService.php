<?php

namespace App\Services;

use App\Models\User;
use App\Support\PhoneLogin;
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
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('phone_login', 'like', "%{$search}%")
                  ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
            });
        }

        if (isset($filters['role']) && $filters['role'] !== '' && $filters['role'] !== null) {
            $roleMap = ['admin' => User::ROLE_ADMIN, 'stylist' => User::ROLE_STYLIST, 'user' => User::ROLE_USER];
            $roleValue = $roleMap[$filters['role']] ?? (int) $filters['role'];
            $query->where('role', $roleValue);
        }

        $sorts = ['newest' => ['created_at', 'desc'], 'oldest' => ['created_at', 'asc'], 'name_asc' => ['first_name', 'asc'], 'name_desc' => ['first_name', 'desc'], 'email_asc' => ['email', 'asc']];
        [$column, $direction] = $sorts[$filters['sort'] ?? 'newest'] ?? $sorts['newest'];
        return $query->orderBy($column, $direction)->orderBy('id')->paginate($perPage);
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
    /**
     * @param int|null $keepTokenId When admins change their own password, the token they use stays.
     */
    public function updateUser($id, array $data, ?int $keepTokenId = null): User
    {
        $user = User::findOrFail($id);

        // Map camelCase to snake_case
        if (isset($data['firstName']) && !isset($data['first_name'])) {
            $data['first_name'] = $data['firstName'];
        }
        if (isset($data['lastName']) && !isset($data['last_name'])) {
            $data['last_name'] = $data['lastName'];
        }

        $newPassword = isset($data['password']);
        if ($newPassword) {
            $data['password'] = Hash::make($data['password']);
        }

        // The sign-in phone follows the account phone, as on the customer's own profile.
        if (! empty($data['phone'])) {
            PhoneLogin::sync($user, $data['phone']);
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

        // An admin usually sets a password because an account was taken over:
        // sign out every device and AI assistant key of that account.
        if ($newPassword) {
            $user->tokens()->when($keepTokenId, fn ($q) => $q->whereKeyNot($keepTokenId))->delete();
        }

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
