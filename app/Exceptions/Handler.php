<?php

namespace App\Exceptions;

use App\Support\ApiResponse;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    public function render($request, Throwable $e)
    {
        if ($request->expectsJson()) {

            if ($e instanceof ValidationException) {
                return ApiResponse::error(
                    'Validation failed',
                    422,
                    $e->errors()
                );
            }

            if ($e instanceof ModelNotFoundException) {
                return ApiResponse::error('Resource not found', 404);
            }

            if ($e instanceof HttpExceptionInterface) {
                return ApiResponse::error(
                    $e->getMessage(),
                    $e->getStatusCode()
                );
            }

            // Never expose internal details in production
            $message = config('app.debug')
                ? $e->getMessage()
                : 'Server error';

            return ApiResponse::error($message, 500);
        }

        return parent::render($request, $e);
    }
}
