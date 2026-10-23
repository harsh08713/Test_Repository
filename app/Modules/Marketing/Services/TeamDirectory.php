<?php

namespace App\Modules\Marketing\Services;

use App\Models\User;
use App\Modules\CRM\Models\SalesTeam;
use App\Modules\CRM\Models\SalesTeamMember;
use App\Modules\Shared\Models\Scopes\Tenancy;

/** Who manages a salesperson today: the manager of the active team they are on. Reads only; nobody (null) when they are on no team or manage it themselves. */
final class TeamDirectory
{
    public function managerOf(int $userId, int $companyId): ?User
    {
        $today = now()->toDateString();
        $teamId = SalesTeamMember::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $companyId)->where('user_id', $userId)->where('joined_on', '<=', $today)
            ->where(fn ($q) => $q->whereNull('left_on')->orWhere('left_on', '>=', $today))->value('sales_team_id');

        if ($teamId === null) {
            return null;
        }

        $managerId = SalesTeam::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $companyId)->where('status', 'active')->whereKey($teamId)->value('manager_user_id');

        return $managerId === null || (int) $managerId === $userId ? null : User::query()->where('is_active', true)->find($managerId);
    }
}
