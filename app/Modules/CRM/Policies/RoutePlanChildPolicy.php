<?php

namespace App\Modules\CRM\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

/** Stops are read and changed only through their plan (RoutePlanPolicy / RoutePlanService); this gives the model its permission form. */
class RoutePlanChildPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'route_plan';
    }
}
