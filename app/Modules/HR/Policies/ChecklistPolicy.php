<?php

namespace App\Modules\HR\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

class ChecklistPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'checklist';
    }
}
