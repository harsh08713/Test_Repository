<?php

namespace App\Modules\Masters\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

class UnitPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'unit';
    }
}
