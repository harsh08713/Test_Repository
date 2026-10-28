<?php

namespace App\Modules\CRM\Policies;

use App\Models\User;
use App\Modules\CRM\Models\Territory;
use App\Modules\Shared\Enums\PermissionAction;
use App\Modules\Shared\Policies\BaseDocumentPolicy;

/** Territories: the permission matrix decides who may work with them; the branch (when a territory has one) is checked by the base policy. */
class TerritoryPolicy extends BaseDocumentPolicy
{
    protected function form(): string
    {
        return 'territory';
    }

    /** Give a territory its coverage, or take it away. */
    public function manageCoverage(User $user, Territory $territory): bool
    {
        return $this->allows($user, PermissionAction::Assign) && $this->withinTenant($user, $territory) && $territory->status === 'active';
    }
}
