<?php

namespace App\Modules\Masters\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

class PriceListPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'price_list';
    }
}
