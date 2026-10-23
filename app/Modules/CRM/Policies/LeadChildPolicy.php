<?php

namespace App\Modules\CRM\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

/** A lead's interests are read and changed only through the lead (LeadPolicy / LeadService); this gives the model its permission form. */
class LeadChildPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'lead';
    }
}
