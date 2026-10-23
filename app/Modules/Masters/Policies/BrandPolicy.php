<?php

namespace App\Modules\Masters\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

class BrandPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'brand';
    }
}
