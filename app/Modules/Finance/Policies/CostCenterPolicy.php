<?php

namespace App\Modules\Finance\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

class CostCenterPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'cost_center';
    }
}
