<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Role name to integer mapping
     */
    private const ROLE_MAP = [
        'admin' => 1,
        'stylist' => 2,
        'user' => 0,
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // Check if user is authenticated
        if (!$request->user()) {
            return ApiResponse::error('Unauthenticated', 401);
        }

        $requiredRoles = array_map(
            fn (string $role) => self::ROLE_MAP[strtolower($role)] ?? $role,
            $roles,
        );

        if (! in_array((int) $request->user()->role, array_map('intval', $requiredRoles), true)) {
            return ApiResponse::error('Unauthorized. Required role: ' . implode(' or ', $roles), 403);
        }

        return $next($request);
    }
}
