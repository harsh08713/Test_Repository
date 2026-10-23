<?php

namespace App\Modules\Finance;

use App\Modules\Finance\Models\Budget;
use App\Modules\Finance\Models\CostCenter;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Finance and costing (Loss Intelligence L5): cost centres, budgets, budget against actual, cost history, profitability. */
class FinanceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Relation::enforceMorphMap(['cost_center' => CostCenter::class, 'budget' => Budget::class]);

        Route::middleware('web')->group(__DIR__.'/routes/web.php');
    }
}
