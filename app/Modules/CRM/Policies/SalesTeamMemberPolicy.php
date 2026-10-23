<?php

namespace App\Modules\CRM\Policies;

use App\Modules\Shared\Policies\BaseDocumentPolicy;

/** Memberships are managed through their team (SalesTeamPolicy::manageMembers); this only gives the model its permission form. */
class SalesTeamMemberPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'sales_team';
    }
}
