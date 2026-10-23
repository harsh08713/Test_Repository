<?php

namespace App\Modules\HR\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

class ShiftPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'shift';
    }
}
