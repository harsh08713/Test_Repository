<?php

namespace App\Modules\CRM\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

/** A deal's lines are read and changed only through the deal (DealPolicy / DealService); this gives the model its permission form. */
class DealChildPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'deal';
    }
}
