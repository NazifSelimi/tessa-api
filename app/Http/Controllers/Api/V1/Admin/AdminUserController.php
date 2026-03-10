<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\AdminUserService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function __construct(
        protected AdminUserService $adminUserService
    ) {}

    /**
     * List all users with pagination and filters.
     */
    public function index(Request $request)
    {
        $filters = $request->only(['search', 'role']);
        $perPage = $request->per_page ?? 20;

        $users = $this->adminUserService->listFiltered($filters, $perPage);

        return ApiResponse::ok(
            UserResource::collection($users)->resolve(),
            200,
            [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ]
        );
    }

    /**
     * Get single user details.
     */
    public function show($id)
    {
        $user = $this->adminUserService->findWithOrderStats($id);

        return ApiResponse::ok(new UserResource($user));
    }

    /**
     * Update user details.
     */
    public function update(Request $request, $id)
    {
        $data = $request->all();

        // Map camelCase to snake_case
        if (isset($data['firstName']) && !isset($data['first_name'])) {
            $data['first_name'] = $data['firstName'];
        }
        if (isset($data['lastName']) && !isset($data['last_name'])) {
            $data['last_name'] = $data['lastName'];
        }

        $validated = validator($data, [
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'role' => ['sometimes', Rule::in(['admin', 'stylist', 'user', 0, 1, 2])],
            'password' => ['sometimes', 'string', 'min:8'],
        ])->validate();

        $user = $this->adminUserService->updateUser($id, $validated);

        return ApiResponse::ok(
            new UserResource($user),
            200,
            [],
            'User updated successfully'
        );
    }

    /**
     * Delete user (soft delete).
     */
    public function destroy($id)
    {
        $result = $this->adminUserService->delete($id, auth()->id());

        if (!$result['deleted']) {
            return ApiResponse::error($result['error'], 400);
        }

        return ApiResponse::ok(null, 200, [], 'User deleted successfully');
    }
}
