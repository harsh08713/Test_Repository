<?php

namespace App\Modules\Marketing\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

/** The message log: opened by the `communication` rights (which messages, is CommunicationAccess's business). Read-only. */
class CommunicationPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'communication';
    }
}
