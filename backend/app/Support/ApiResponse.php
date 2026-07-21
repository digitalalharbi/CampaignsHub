<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Builds the platform-wide API response envelope.
 *
 * Shape:
 *  { "success": bool, "message": string, "data": mixed|null,
 *    "meta": object, "errors": object|null }
 *
 * `meta.request_id` is always present so a client can correlate a response with server logs.
 */
final class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'Operation completed successfully.',
        array $meta = [],
        int $status = 200,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => self::withRequestId($meta),
            'errors' => null,
        ], $status);
    }

    public static function error(
        string $message = 'The request could not be processed.',
        ?array $errors = null,
        array $meta = [],
        int $status = 400,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'meta' => self::withRequestId($meta),
            'errors' => $errors,
        ], $status);
    }

    /** Ensure meta always carries the current request id. */
    private static function withRequestId(array $meta): array
    {
        if (! array_key_exists('request_id', $meta)) {
            /** @var Request $request */
            $request = request();
            $meta['request_id'] = $request->attributes->get('request_id')
                ?? $request->headers->get('X-Request-Id')
                ?? 'req_'.bin2hex(random_bytes(8));
        }

        return $meta;
    }
}
