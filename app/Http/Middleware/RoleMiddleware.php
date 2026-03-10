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
    public function handle(Request $request, Closure $next, string $role): Response
    {
        // Check if user is authenticated
        if (!$request->user()) {
            return ApiResponse::error('Unauthenticated', 401);
        }

        // Convert role name to integer if it's a string
        $requiredRole = self::ROLE_MAP[strtolower($role)] ?? $role;

        // Check if user has the required role
        if ((int) $request->user()->role !== (int) $requiredRole) {
            return ApiResponse::error('Unauthorized. Required role: ' . $role, 403);
        }

        return $next($request);
    }
}
