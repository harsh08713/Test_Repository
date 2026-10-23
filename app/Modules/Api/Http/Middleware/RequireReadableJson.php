<?php

namespace App\Modules\Api\Http\Middleware;

use App\Modules\Api\Support\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A body that says it is JSON must be JSON — an object or a list. Without this a broken body is read as "nothing was sent" and
 * the app is told the required fields are missing, which sends its developer looking in the wrong place; with it the answer is
 * 400 `bad_request`.
 */
class RequireReadableJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $body = $request->getContent();

        if ($request->isJson() && $body !== '') {
            $decoded = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                throw new ApiException('bad_request', 'The request body is not valid JSON. Send a JSON object.', 400);
            }
        }

        return $next($request);
    }
}
