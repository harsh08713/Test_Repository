<?php

namespace App\Modules\Shared\Http\Middleware;

use App\Modules\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every web request after the session starts:
 *  - signs out a user who was deactivated while logged in
 *  - resolves the active company once, so a user with no valid company fails closed
 */
class SetTenantContext
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Your account has been deactivated.']);
        }

        if ($user !== null) {
            $this->tenant->activeCompanyId();
        }

        return $next($request);
    }
}
