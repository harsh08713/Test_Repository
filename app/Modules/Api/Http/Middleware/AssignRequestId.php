<?php

namespace App\Modules\Api\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every API request a correlation id: the caller's own `X-Request-Id` when it is a sane token (so a client can trace its
 * request end to end), otherwise a new one. It goes into every log line written during the request, into the response header
 * and into the `meta.request_id` of the body.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $given = (string) $request->header('X-Request-Id', '');
        $id = preg_match('/^[A-Za-z0-9._\-]{8,64}$/', $given) === 1 ? $given : (string) Str::ulid();

        $request->attributes->set('request_id', $id);
        Log::shareContext(['request_id' => $id]);   // every channel, including ones not opened yet

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
