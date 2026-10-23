<?php

namespace App\Modules\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers that make a browser safer without changing how the application works: no content-type sniffing, no framing by other sites, a short referrer, and a permission policy that
 * leaves only what the application uses (the field-sales position check needs location; nothing needs the camera, microphone, payment or USB). HSTS is sent only on an HTTPS request in
 * production, so a local or test server is never told to insist on HTTPS. A header a response already set (a file download's own `nosniff`) is left as it is.
 *
 * There is deliberately **no Content-Security-Policy**: Livewire and Alpine evaluate expressions in the page, so a policy strict enough to matter would break them. See docs/operations.md.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(self), camera=(), microphone=(), payment=(), usb=()',
        ];

        if ($request->isSecure() && app()->isProduction()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            $response->headers->has($name) || $response->headers->set($name, $value);
        }

        return $response;
    }
}
