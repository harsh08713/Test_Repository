<?php

namespace App\Modules\Api\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The one JSON shape every API answer has:
 *
 *   success  {"data": …, "meta": {"request_id": "…"}}              (lists add meta.pagination and meta.server_time)
 *   failure  {"error": {"code": "…", "message": "…", "fields": {…}, "details": {…}}, "meta": {"request_id": "…"}}
 *
 * `request_id` is the correlation id also sent in the X-Request-Id header and written to the server log, so a report from a
 * phone can be traced to the exact request.
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $headers
     */
    public static function data(mixed $data, int $status = 200, array $meta = [], array $headers = []): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => [...self::baseMeta(), ...$meta]], $status, $headers);
    }

    /**
     * A page of records. `$items` are already in their wire form; `$serverTime` is when the query began — what a client
     * sends back as `updated_since` next time, so nothing changed while the pages were being read is missed.
     *
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $meta
     */
    public static function page(LengthAwarePaginator $paginator, array $items, array $meta = []): JsonResponse
    {
        return self::data($items, 200, [
            'server_time' => ApiTime::iso(now()),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
            ...$meta,
        ]);
    }

    /**
     * @param  array<string, list<string>>  $fields
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public static function error(string $code, string $message, int $status, array $fields = [], array $details = [], array $headers = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];

        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json(['error' => $error, 'meta' => self::baseMeta()], $status, $headers);
    }

    /** @return array<string, mixed> */
    private static function baseMeta(): array
    {
        return ['request_id' => request()->attributes->get('request_id')];
    }
}
