<?php

namespace App\Modules\HR\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

class LeaveTypePolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'leave_type';
    }
}
