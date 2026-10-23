<?php

namespace App\Modules\Production;

use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\BomItem;
use App\Modules\Production\Models\ProductionComponent;
use App\Modules\Production\Models\ProductionIssue;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOutput;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/** Production (P1): bills of materials, production orders, material issue and output into stock. */
class ProductionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'bom' => Bom::class, 'bom_item' => BomItem::class, 'production_order' => ProductionOrder::class, 'production_component' => ProductionComponent::class,
            'production_issue' => ProductionIssue::class, 'production_output' => ProductionOutput::class,
        ]);

        Route::middleware('web')->group(__DIR__.'/routes/web.php');
    }
}
