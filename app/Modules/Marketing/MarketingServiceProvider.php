<?php

namespace App\Modules\Marketing;

use App\Modules\CRM\Models\Lead;
use App\Modules\Marketing\Console\RunMarketing;
use App\Modules\Marketing\Console\RunWorkflows;
use App\Modules\Marketing\Http\Controllers\ChannelWebhookController;
use App\Modules\Marketing\Models\Campaign;
use App\Modules\Marketing\Models\CampaignMember;
use App\Modules\Marketing\Models\CampaignStep;
use App\Modules\Marketing\Models\Communication;
use App\Modules\Marketing\Models\ContactConsent;
use App\Modules\Marketing\Models\MessageTemplate;
use App\Modules\Marketing\Models\Workflow;
use App\Modules\Marketing\Models\WorkflowRun;
use App\Modules\Marketing\Models\WorkflowVersion;
use App\Modules\Marketing\Services\CampaignAttribution;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Throwable;

/** Campaigns, message templates, consent, the message log and workflow automation (Phase 15). */
class MarketingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'campaign' => Campaign::class,
            'campaign_step' => CampaignStep::class,
            'campaign_member' => CampaignMember::class,
            'message_template' => MessageTemplate::class,
            'communication' => Communication::class,
            'contact_consent' => ContactConsent::class,
            'workflow' => Workflow::class,
            'workflow_version' => WorkflowVersion::class,
            'workflow_run' => WorkflowRun::class,
        ]);

        // A new lead that nobody tagged is credited to the campaign that wrote to the same person lately — whichever way the lead arrived.
        Lead::created(function (Lead $lead) {
            try {
                app(CampaignAttribution::class)->inferFor($lead);
            } catch (Throwable $e) {
                report($e);   // attribution must never stop a lead from being saved
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([RunMarketing::class, RunWorkflows::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('marketing:run')->everyFiveMinutes()->withoutOverlapping();
            $schedule->command('automation:run')->everyFiveMinutes()->withoutOverlapping();
        });

        // Where WhatsApp and SMS providers report back (stateless, no CSRF): 300 a minute to one company's address and 900 from one address
        RateLimiter::for('channel-webhooks', fn (Request $request) => [
            Limit::perMinute(300)->by('channel:'.$request->route('company').':'.$request->route('channel')),
            Limit::perMinute(900)->by('channel-ip:'.$request->ip()),
        ]);

        Route::middleware(['api', 'throttle:channel-webhooks'])->prefix('api/webhooks/channels')->group(function () {
            Route::get('{company}/{channel}/{token}', [ChannelWebhookController::class, 'verify'])->whereNumber('company');
            Route::post('{company}/{channel}/{token}', [ChannelWebhookController::class, 'receive'])->whereNumber('company')->name('webhooks.channel');
        });

        Route::middleware('web')->group(__DIR__.'/routes/web.php');
    }
}
