<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    public static function ok(mixed $data = null, int $status = 200, mixed $meta = null, ?string $message = null): JsonResponse
    {
        $payload = [
            'success' => true,
            'data' => $data,
        ];

        if (!is_null($message)) {
            $payload['message'] = $message;
        }

        if (!is_null($meta) && (!is_array($meta) || count($meta) > 0)) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    public static function error(string $message, int $status = 400, mixed $details = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $details,
        ], $status);
    }
}
