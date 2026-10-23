<?php

namespace App\Modules\CRM;

use App\Modules\CRM\Console\SendFollowupReminders;
use App\Modules\CRM\Contracts\AchievementSource;
use App\Modules\CRM\Imports\LeadImporter;
use App\Modules\CRM\Models\AttendanceRecord;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\DealItem;
use App\Modules\CRM\Models\DealStageEntry;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\FollowupOutcome;
use App\Modules\CRM\Models\GeofenceViolation;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadInterest;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\LeadStageEntry;
use App\Modules\CRM\Models\LeadTransfer;
use App\Modules\CRM\Models\LostReason;
use App\Modules\CRM\Models\RoutePlan;
use App\Modules\CRM\Models\RouteStop;
use App\Modules\CRM\Models\SalesTarget;
use App\Modules\CRM\Models\SalesTeam;
use App\Modules\CRM\Models\SalesTeamMember;
use App\Modules\CRM\Support\NullAchievementSource;
use App\Modules\Shared\Imports\ImporterRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** CRM-Marketing module: sales teams and targets, route plans and attendance, leads and follow-ups, deals (quotations follow). */
class CRMServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Where "achieved" comes from. Sales Orders (Phase 12) replace this binding with approved-order totals.
        $this->app->bind(AchievementSource::class, NullAchievementSource::class);
    }

    public function boot(): void
    {
        Relation::enforceMorphMap([
            'sales_team' => SalesTeam::class,
            'sales_team_member' => SalesTeamMember::class,
            'sales_target' => SalesTarget::class,
            'route_plan' => RoutePlan::class,
            'route_stop' => RouteStop::class,
            'attendance' => AttendanceRecord::class,
            'geofence_violation' => GeofenceViolation::class,
            'lead_source' => LeadSource::class,
            'followup_outcome' => FollowupOutcome::class,
            'lost_reason' => LostReason::class,
            'lead' => Lead::class,
            'lead_interest' => LeadInterest::class,
            'lead_transfer' => LeadTransfer::class,
            'lead_stage_entry' => LeadStageEntry::class,
            'followup' => Followup::class,
            'deal' => Deal::class,
            'deal_item' => DealItem::class,
            'deal_stage_entry' => DealStageEntry::class,
        ]);

        $this->app->make(ImporterRegistry::class)->register(new LeadImporter);

        if ($this->app->runningInConsole()) {
            $this->commands([SendFollowupReminders::class]);
        }

        // Follow-up reminders: every five minutes, never two runs at once (each reminder is also claimed atomically).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('crm:followup-reminders')->everyFiveMinutes()->withoutOverlapping();
        });

        Route::middleware('web')->group(__DIR__.'/routes/web.php');
    }
}
