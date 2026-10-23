<?php

namespace App\Modules\Sales\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

/** A sales order's lines are read and changed only through the order (SalesOrderPolicy / SalesOrderService); this gives the model its permission form. */
class SalesOrderChildPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'sales_order';
    }
}
