<?php

namespace App\Modules\Procurement\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

/** A receipt's lines are read and changed only through the receipt (GrnPolicy / GrnService); this gives the model its permission form. */
class GrnChildPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'grn';
    }
}
