<?php

namespace Tests\Support;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

class TestDocumentPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'item';
    }
}
