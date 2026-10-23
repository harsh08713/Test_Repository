<?php

namespace App\Modules\Masters\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

class DesignationPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'designation';
    }
}
