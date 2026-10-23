<?php

namespace App\Modules\Procurement\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

/** An order's lines are read and changed only through the order (PurchaseOrderPolicy / PurchaseOrderService); this gives the model its permission form. */
class PurchaseOrderChildPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'purchase_order';
    }
}
