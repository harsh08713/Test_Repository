<?php

namespace App\Modules\Sales\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

/** An invoice's lines are read only through the invoice; this gives the model its permission form. */
class SalesInvoiceChildPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'sales_invoice';
    }
}
