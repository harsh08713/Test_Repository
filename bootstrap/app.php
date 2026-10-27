<?php

use App\Modules\Api\Support\ApiException;
use App\Modules\Api\Support\ApiExceptionRenderer;
use App\Modules\CRM\Exceptions\AttendanceRefused;
use App\Modules\CRM\Exceptions\FieldPositionRefused;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Integrations\Http\Middleware\WebsiteFormCors;
use App\Modules\Shared\Http\Middleware\SecurityHeaders;
use App\Modules\Shared\Http\Middleware\SetTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Module routes are registered by each module's service provider (see SharedServiceProvider; the API's are in ApiServiceProvider).
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SetTenantContext::class]);
        $middleware->append(SecurityHeaders::class);   // every response, errors and unknown paths included

        // The website form's CORS headers must also be on a throttled answer, so it has to run before the throttle (which Laravel sorts ahead of anything it does not know).
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: WebsiteFormCors::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A refusal the person was told about (a lost lead, a position outside the geofence) is not an error worth reporting.
        $exceptions->dontReport([ApiException::class, FieldRuleException::class, AttendanceRefused::class, FieldPositionRefused::class]);

        // Anything that goes wrong under /api/ is answered in the API's own JSON envelope, never with an HTML page or a redirect.
        $exceptions->render(fn (Throwable $e, Request $request) => ApiExceptionRenderer::render($e, $request));
    })->create();
