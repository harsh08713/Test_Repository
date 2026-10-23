<?php

namespace App\Modules\Api\Http\Middleware;

use App\Modules\Api\Models\ApiToken;
use App\Modules\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * One line per API request in `storage/logs/api.log`: who, what, how it ended, how long it took — and nothing the person
 * typed. No body, no query *values* (a search term can be a phone number), no headers, no token. The path is the route's
 * pattern (`api/v1/leads/{lead}`), so record ids do not spread through the log either; the request id ties the line to the
 * rest of the log.
 */
class LogApiRequest
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);

        $response = $next($request);

        $status = $response->getStatusCode();
        $user = $request->user();
        $token = $user?->currentAccessToken();

        Log::channel('api')->log($status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info'), 'api request', [
            'request_id' => $request->attributes->get('request_id'),
            'method' => $request->method(),
            'route' => $request->route()?->uri() ?? $request->path(),
            'status' => $status,
            'ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            'user_id' => $user?->getAuthIdentifier(),
            'token_id' => $token instanceof ApiToken ? $token->getKey() : null,
            'company_id' => $user === null ? null : $this->tenant->activeCompanyId(),
            'ip' => $request->ip(),
            'query_keys' => array_keys($request->query()),
            'replayed' => $response->headers->get('Idempotent-Replayed') === 'true' ? true : null,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 120),
        ]);

        return $response;
    }
}
