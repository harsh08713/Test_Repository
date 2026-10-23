<?php

namespace App\Modules\Sales\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

/** A quotation's lines are read and changed only through the quotation (QuotationPolicy / QuotationService); this gives the model its permission form. */
class QuotationChildPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'quotation';
    }
}
