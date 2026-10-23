<?php

namespace App\Modules\Api;

use App\Models\User;
use App\Modules\Api\Console\PruneApiData;
use App\Modules\Api\Http\Middleware\EstablishApiContext;
use App\Modules\Api\Http\Middleware\Idempotency;
use App\Modules\Api\Models\ApiToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * The mobile / integrations API under `/api/v1`: Sanctum device tokens, throttling, idempotency keys, the JSON envelope. It owns
 * no business rules — every endpoint calls the services the screens use — so what the API allows can never drift from what the
 * application allows.
 */
class ApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(ApiToken::class);

        $this->app->make(Router::class)->aliasMiddleware('api.context', EstablishApiContext::class);
        $this->app->make(Router::class)->aliasMiddleware('idempotent', Idempotency::class);

        $this->limiters();

        Route::group([], __DIR__.'/routes/v1.php');

        // A device's token is the person: when they are switched off, or their password changes, every token goes with it.
        User::updated(function (User $user) {
            if (($user->wasChanged('is_active') && ! $user->is_active) || $user->wasChanged('password')) {
                $user->tokens()->delete();
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([PruneApiData::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('api:prune')->daily();
            $schedule->command('sanctum:prune-expired', ['--hours' => 24])->daily();
        });
    }

    /**
     * Limits per minute (config `formezy.api.limits`). Sign-in is counted per e-mail address *and* IP address, and per IP address
     * alone, so neither guessing one account's password nor trying many accounts from one place gets far. After sign-in a device is
     * limited by its token (reads and writes separately, writes tighter) and, more generously, by IP — a whole depot can sit behind one.
     */
    private function limiters(): void
    {
        $limits = (array) config('formezy.api.limits');

        RateLimiter::for('api-health', fn (Request $request) => Limit::perMinute((int) $limits['health_per_minute'])->by('health:'.$request->ip()));

        RateLimiter::for('api-login', fn (Request $request) => [
            Limit::perMinute((int) $limits['login_per_minute'])->by('login:'.Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute((int) $limits['login_ip_per_minute'])->by('login-ip:'.$request->ip()),
        ]);

        RateLimiter::for('api', function (Request $request) use ($limits) {
            $token = $request->user()?->currentAccessToken();
            $who = is_object($token) && isset($token->id) ? 'token:'.$token->id : 'ip:'.$request->ip();

            $rules = [
                Limit::perMinute((int) $limits['per_minute'])->by('api:'.$who),
                Limit::perMinute((int) $limits['ip_per_minute'])->by('api-ip:'.$request->ip()),
            ];

            if (! $request->isMethodSafe()) {
                $rules[] = Limit::perMinute((int) $limits['writes_per_minute'])->by('api-write:'.$who);
            }

            return $rules;
        });
    }
}
