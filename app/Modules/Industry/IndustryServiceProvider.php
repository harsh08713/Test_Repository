<?php

namespace App\Modules\Industry;

use App\Modules\Industry\Models\IndustryTemplate;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Industry templates (Loss Intelligence L1): the 15 shared templates, their KPIs, loss categories, fields and workflow. */
class IndustryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Relation::enforceMorphMap(['industry_template' => IndustryTemplate::class]);

        Route::middleware('web')->group(__DIR__.'/routes/web.php');
    }
}
