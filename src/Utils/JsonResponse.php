<?php

declare(strict_types=1);

namespace App\Utils;

/**
 * JsonResponse
 *
 * Provides static factory methods for building uniform JSON API responses.
 *
 * Response envelope:
 *   { "success": bool, "data": mixed, "message": string }
 *
 * Usage:
 *   JsonResponse::success(['id' => 5]);
 *   JsonResponse::error('Ungültige Eingabe', 422);
 *   JsonResponse::paginated($items, 3, 10, 100);
 */
final class JsonResponse
{
    /**
     * Emit a successful JSON response and terminate execution.
     *
     * @param mixed       $data    Response payload (any JSON-serialisable value)
     * @param string      $message Optional human-readable success message
     * @param int         $status  HTTP status code (default 200)
     */
    public static function success(mixed $data = null, string $message = '', int $status = 200): never
    {
        self::emit(['success' => true, 'data' => $data, 'message' => $message], $status);
    }

    /**
     * Emit an error JSON response and terminate execution.
     *
     * @param string $message Human-readable error description
     * @param int    $status  HTTP status code (default 400)
     * @param mixed  $data    Optional extra error context
     */
    public static function error(string $message, int $status = 400, mixed $data = null): never
    {
        self::emit(['success' => false, 'data' => $data, 'message' => $message], $status);
    }

    /**
     * Emit a paginated list response and terminate execution.
     *
     * @param array<int, mixed> $items       The current page's items
     * @param int               $page        Current page number (1-based)
     * @param int               $perPage     Items per page
     * @param int               $total       Total number of items across all pages
     * @param string            $message     Optional message
     */
    public static function paginated(
        array  $items,
        int    $page,
        int    $perPage,
        int    $total,
        string $message = ''
    ): never {
        $totalPages = $perPage > 0 ? (int)ceil($total / $perPage) : 0;

        self::emit([
            'success' => true,
            'data'    => $items,
            'message' => $message,
            'meta'    => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => $totalPages,
            ],
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     */
    private static function emit(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
