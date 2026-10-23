<?php

namespace App\Modules\Sales\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

/** A return's lines are read and changed only through the return (SalesReturnPolicy / SalesReturnService); this gives the model its permission form. */
class SalesReturnChildPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'sales_return';
    }
}
