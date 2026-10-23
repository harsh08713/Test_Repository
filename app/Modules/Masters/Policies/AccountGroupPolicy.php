<?php

namespace App\Modules\Masters\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

class AccountGroupPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'account_group';
    }
}
