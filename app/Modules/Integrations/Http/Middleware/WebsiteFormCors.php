<?php

namespace App\Modules\Integrations\Http\Middleware;

use App\Modules\Api\Support\ApiException;
use App\Modules\Integrations\Models\IntegrationConnection;
use App\Modules\Integrations\Services\WebsiteOrigins;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a visitor's browser needs from the website form's address, and nothing the other providers get.
 *
 *  - A browser that says which page it is on (`Origin`) is let through only if the company has listed that site (`WebsiteOrigins`); any other site is
 *    refused **403 `origin_not_allowed`**. A request with no `Origin` (a server posting the form, a command line) is not a browser and is judged by the
 *    rest of the checks alone: this list keeps *other websites' pages* from using the form, it does not stop a script, and says so.
 *  - The browser's preflight (`OPTIONS`) is answered here with what it asked about, and every answer — including a refusal or a throttle — carries
 *    `Access-Control-Allow-Origin` for the listed site, so the page's script can read why it failed.
 *  - A plain HTML form (no script) that names a `thanks_url` on a listed site is sent there with `303` after it is accepted, instead of being shown a JSON answer.
 *
 * An address that is not a switched-on website connection is left alone: the controller answers it 404 like any wrong address, with no hint.
 */
final class WebsiteFormCors
{
    public function __construct(private readonly WebsiteOrigins $origins) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->route('provider') !== 'website') {
            return $next($request);
        }

        $connection = IntegrationConnection::withoutGlobalScopes(Tenancy::SCOPES)->where('provider', 'website')->where('public_key', (string) $request->route('key'))->where('enabled', true)->first();
        $origin = $request->headers->get('Origin');

        if ($connection === null || $origin === null) {
            return $request->isMethod('OPTIONS') ? response('', 404) : $next($request);
        }

        $companyId = (int) $connection->company_id;

        if (! $this->origins->allows($companyId, $origin)) {
            throw new ApiException('origin_not_allowed', 'This website is not allowed to send leads here. Ask the company to add it under Company settings → Integrations.', 403);
        }

        $allow = (string) WebsiteOrigins::normalise($origin);

        if ($request->isMethod('OPTIONS')) {
            return response('', 204, [
                'Access-Control-Allow-Origin' => $allow, 'Access-Control-Allow-Methods' => 'POST, OPTIONS', 'Access-Control-Allow-Headers' => 'Content-Type, Accept',
                'Access-Control-Max-Age' => '600', 'Vary' => 'Origin',
            ]);
        }

        $response = $next($request);
        $response->headers->set('Access-Control-Allow-Origin', $allow);
        $response->headers->set('Access-Control-Expose-Headers', 'X-Request-Id, Retry-After');
        $response->headers->set('Vary', 'Origin', false);

        $thanks = $request->isJson() || $response->getStatusCode() !== 202 ? null : $this->thanks($request, $companyId);

        return $thanks === null ? $response : redirect()->to($thanks, 303)->withHeaders(['Access-Control-Allow-Origin' => $allow, 'Vary' => 'Origin']);
    }

    /** The page to send a plain form's visitor to — only on a site the company has listed, never anywhere a form field can point. */
    private function thanks(Request $request, int $companyId): ?string
    {
        $url = $request->input('thanks_url');

        return is_string($url) && $this->origins->allows($companyId, $url) ? $url : null;
    }
}
