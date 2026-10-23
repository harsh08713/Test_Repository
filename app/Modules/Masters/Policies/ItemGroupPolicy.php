<?php

namespace App\Modules\Masters\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

class ItemGroupPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'item_group';
    }
}
