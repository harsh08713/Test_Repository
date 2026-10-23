<?php

namespace App\Modules\Quality;

use App\Modules\Quality\Models\BatchHold;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionPlan;
use App\Modules\Quality\Models\InspectionPlanParameter;
use App\Modules\Quality\Models\InspectionResult;
use App\Modules\Quality\Models\NonConformance;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Quality management (Q1): inspection plans, inspections with results, batch hold and release, non-conformance reports. */
class QualityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'inspection_plan' => InspectionPlan::class, 'inspection_plan_parameter' => InspectionPlanParameter::class, 'inspection' => Inspection::class,
            'inspection_result' => InspectionResult::class, 'batch_hold' => BatchHold::class, 'non_conformance' => NonConformance::class,
        ]);

        Route::middleware('web')->group(__DIR__.'/routes/web.php');
    }
}
