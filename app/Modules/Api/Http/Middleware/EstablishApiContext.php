<?php

namespace App\Modules\Api\Http\Middleware;

use App\Models\User;
use App\Modules\Api\Support\ApiException;
use App\Modules\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after the device token has been accepted and settles, once, everything the rest of the request relies on — each
 * decided here on the server, whatever the app says:
 *
 *  - the account is still active (a deactivated person's tokens stop working at once, and are removed);
 *  - the account may use the API at all (`api.access`) — checked on every request, so taking the permission away takes effect
 *    without waiting for the token to expire;
 *  - the token's scope covers the call: `read` for a GET, `write` for anything that changes data (`:self` skips this for the
 *    two calls a read-only device must still make — sign out and renew);
 *  - the company: the user's default, or the one named in `X-Company-Id` — and a named company the person does not belong to
 *    is **refused**, never quietly swapped for another (that would let an app believe it is writing to one company while it
 *    writes to a different one).
 */
class EstablishApiContext
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next, string $mode = ''): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        if (! $user->is_active) {
            $user->tokens()->delete();

            throw ApiException::accountInactive();
        }

        if (! $user->can('api.access')) {
            throw ApiException::apiAccessDenied();
        }

        if ($mode !== 'self') {
            $scope = in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) ? 'read' : 'write';
            $token = $user->currentAccessToken();

            if (! $token->can($scope)) {
                throw ApiException::scopeMissing($scope);
            }
        }

        $named = $request->header('X-Company-Id');

        if ($named !== null && $named !== '') {
            if (! ctype_digit($named) || ! $this->tenant->userCanAccessCompany($user, (int) $named)) {
                throw ApiException::companyNotAccessible();
            }
        }

        if ($this->tenant->activeCompanyId() === null) {
            throw ApiException::noCompany();
        }

        return $next($request);
    }
}
