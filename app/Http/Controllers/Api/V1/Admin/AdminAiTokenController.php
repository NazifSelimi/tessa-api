<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * Tokens an admin gives an AI assistant to reach the shop's MCP server
 * (/api/mcp). Shown once when created; revoke any time.
 */
class AdminAiTokenController extends Controller
{
    private const PREFIX = 'ai-assistant: ';

    public function index(Request $request)
    {
        $tokens = $request->user()->tokens()->where('name', 'like', self::PREFIX . '%')->latest('id')->get();

        return ApiResponse::ok([
            'endpoint' => rtrim((string) config('app.url'), '/') . '/api/mcp',
            'tokens' => $tokens->map(fn ($token) => [
                'id' => (string) $token->id,
                'name' => substr($token->name, strlen(self::PREFIX)),
                'lastUsedAt' => $token->last_used_at?->toISOString(),
                'createdAt' => $token->created_at?->toISOString(),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);
        $token = $request->user()->createToken(self::PREFIX . $data['name'], ['*'], now()->addYear());

        return ApiResponse::ok([
            'id' => (string) $token->accessToken->id,
            'token' => $token->plainTextToken,
            'endpoint' => rtrim((string) config('app.url'), '/') . '/api/mcp',
            'expiresAt' => $token->accessToken->expires_at?->toISOString(),
        ], 201, null, 'Copy the token now; it is shown only once.');
    }

    public function destroy(Request $request, int $id)
    {
        $deleted = $request->user()->tokens()->where('name', 'like', self::PREFIX . '%')->whereKey($id)->delete();

        return $deleted ? ApiResponse::ok(null, 200, null, 'Token revoked') : ApiResponse::error('Token not found', 404);
    }
}
