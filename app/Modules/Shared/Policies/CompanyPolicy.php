<?php

namespace App\Modules\Shared\Policies;

class CompanyPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'company';
    }
}
