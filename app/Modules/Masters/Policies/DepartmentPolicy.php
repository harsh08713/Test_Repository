<?php

namespace App\Modules\Masters\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

class DepartmentPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'department';
    }
}
