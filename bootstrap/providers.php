<?php

use App\Modules\Analytics\AnalyticsServiceProvider;
use App\Modules\Api\ApiServiceProvider;
use App\Modules\CRM\CRMServiceProvider;
use App\Modules\Finance\FinanceServiceProvider;
use App\Modules\HR\HRServiceProvider;
use App\Modules\Industry\IndustryServiceProvider;
use App\Modules\Integrations\IntegrationsServiceProvider;
use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\Loss\LossServiceProvider;
use App\Modules\Maintenance\MaintenanceServiceProvider;
use App\Modules\Marketing\MarketingServiceProvider;
use App\Modules\Masters\MastersServiceProvider;
use App\Modules\Procurement\ProcurementServiceProvider;
use App\Modules\Production\ProductionServiceProvider;
use App\Modules\Quality\QualityServiceProvider;
use App\Modules\Reports\ReportsServiceProvider;
use App\Modules\Sales\SalesServiceProvider;
use App\Modules\Shared\SharedServiceProvider;
use App\Modules\Workspace\WorkspaceServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    SharedServiceProvider::class,
    MastersServiceProvider::class,
    CRMServiceProvider::class,
    ApiServiceProvider::class,
    IntegrationsServiceProvider::class,
    SalesServiceProvider::class,
    ReportsServiceProvider::class,
    WorkspaceServiceProvider::class,
    MarketingServiceProvider::class,
    IndustryServiceProvider::class,
    InventoryServiceProvider::class,
    ProcurementServiceProvider::class,
    LossServiceProvider::class,
    FinanceServiceProvider::class,
    AnalyticsServiceProvider::class,
    HRServiceProvider::class,
    ProductionServiceProvider::class,
    QualityServiceProvider::class,
    MaintenanceServiceProvider::class,
];
