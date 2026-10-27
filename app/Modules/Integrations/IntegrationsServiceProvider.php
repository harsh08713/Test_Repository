<?php

namespace App\Modules\Integrations;

use App\Modules\Api\Http\Middleware\AssignRequestId;
use App\Modules\Api\Http\Middleware\LogApiRequest;
use App\Modules\Api\Http\Middleware\RequireReadableJson;
use App\Modules\Integrations\Console\PruneLeadLogs;
use App\Modules\Integrations\Console\PullLeads;
use App\Modules\Integrations\Console\RetryStuckLeads;
use App\Modules\Integrations\Http\Controllers\WebhookController;
use App\Modules\Integrations\Http\Middleware\WebsiteFormCors;
use App\Modules\Integrations\Models\IntegrationConnection;
use App\Modules\Integrations\Providers\IndiaMartProvider;
use App\Modules\Integrations\Providers\JustDialProvider;
use App\Modules\Integrations\Providers\ProviderRegistry;
use App\Modules\Integrations\Providers\WebsiteProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The integration hub, as far as lead marketplaces go: provider adapters (IndiaMART, Just Dial), the webhook address they push to, the
 * scheduled pull, the log of what each delivered and the screens to run it. Adapters know their provider's wire format and nothing
 * else; the intake that makes leads is the same for all of them. Later providers (WhatsApp, SMS, e-mail, telephony — Phase 16) join
 * the same hub.
 */
class IntegrationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderRegistry::class, function () {
            $registry = new ProviderRegistry;
            $registry->register(new IndiaMartProvider);
            $registry->register(new JustDialProvider);
            $registry->register(new WebsiteProvider);

            return $registry;
        });
    }

    public function boot(): void
    {
        Relation::enforceMorphMap(['integration_connection' => IntegrationConnection::class]);

        // 120 deliveries a minute to one connection and 600 from one address: a busy marketplace account, not a flood
        // A website form is posted by strangers' browsers, so one visitor gets far less than a marketplace's server: ten a minute from one address.
        RateLimiter::for('webhooks', fn (Request $request) => [
            Limit::perMinute(120)->by('webhook:'.$request->route('provider').':'.$request->route('key')),
            Limit::perMinute(600)->by('webhook-ip:'.$request->ip()),
            ...($request->route('provider') === 'website' ? [Limit::perMinute(10)->by('webhook-visitor:'.$request->ip().':'.$request->route('key'))] : []),
        ]);

        // WebsiteFormCors comes first so that even a refusal or a throttle carries the headers a browser needs to read it. Laravel sorts the throttle
        // middleware ahead of anything it does not know, so it is also put before the throttle in the priority list (bootstrap/app.php).
        Route::middleware(['api', WebsiteFormCors::class, AssignRequestId::class, LogApiRequest::class, RequireReadableJson::class, 'throttle:webhooks'])
            ->group(function () {
                Route::post('api/webhooks/{provider}/{key}', WebhookController::class)->name('webhooks.receive');
                // the browser's preflight for a website form (WebsiteFormCors answers it; for any other provider the address is not found)
                Route::options('api/webhooks/{provider}/{key}', fn () => abort(404))->name('webhooks.preflight');
            });

        Route::middleware('web')->group(__DIR__.'/routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([PullLeads::class, RetryStuckLeads::class, PruneLeadLogs::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('integrations:pull')->everyFiveMinutes()->withoutOverlapping();
            $schedule->command('integrations:retry-stuck')->everyTenMinutes()->withoutOverlapping();
            $schedule->command('integrations:prune')->daily();
        });
    }
}
