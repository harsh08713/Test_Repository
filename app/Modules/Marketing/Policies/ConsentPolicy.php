<?php

namespace App\Modules\Marketing\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

/** Consent history: seen and recorded by the `consent` rights; an entry is never changed or deleted. */
class ConsentPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'consent';
    }
}
