<?php

namespace App\Modules\Workspace\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

/** SLA policies and holidays: ordinary company-wide masters, governed by the `sla_policy` rights. */
class SlaPolicyPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'sla_policy';
    }
}
