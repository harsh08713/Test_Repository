<?php

namespace App\Modules\Shared\Policies;

class LocationPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'location';
    }
}
