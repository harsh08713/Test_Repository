<?php

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\CRM\Models\SalesTeam;
use App\Modules\CRM\Models\SalesTeamMember;

/**
 * Whose work a person may plan, measure and see: the answer to "is this salesperson mine?".
 *
 *  - A person always sees their own plan, attendance and targets.
 *  - A **team manager** (the manager of an *active* team) also acts on the team's *current* members.
 *  - Holders of the explicit permission `sales.scope_all` act on every salesperson of the company (administrators). There is
 *    no role-name shortcut: the reach is a permission that can be given or taken away, like the other break-glass rights.
 *
 * It decides who; what they may do to that person's records is the policies' business (permission, branch). The company
 * boundary is never decided here: every query below runs through the company scope.
 */
final class SalesScope
{
    public const ALL_PERMISSION = 'sales.scope_all';

    public function seesEveryone(User $actor): bool
    {
        return $actor->can(self::ALL_PERMISSION);
    }

    /**
     * The people $actor manages — the current members of the active teams they run, on $date (default today) — or null
     * for "everybody" (a holder of `sales.scope_all`). The actor themselves is not included.
     *
     * @return list<int>|null
     */
    public function managedUserIds(User $actor, ?string $date = null): ?array
    {
        if ($this->seesEveryone($actor)) {
            return null;
        }

        $date ??= now()->toDateString();

        return SalesTeamMember::query()
            ->whereIn('sales_team_id', SalesTeam::query()->where('manager_user_id', $actor->getKey())->where('status', 'active')->select('id'))
            ->where('joined_on', '<=', $date)
            ->where(fn ($q) => $q->whereNull('left_on')->orWhere('left_on', '>=', $date))
            ->where('user_id', '!=', $actor->getKey())
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whose records $actor may see: their own plus everyone they manage; null means everyone.
     *
     * @return list<int>|null
     */
    public function visibleUserIds(User $actor, ?string $date = null): ?array
    {
        $managed = $this->managedUserIds($actor, $date);

        return $managed === null ? null : array_values(array_unique([...$managed, (int) $actor->getKey()]));
    }

    /** May $actor plan, set targets for or override the attendance of this person? Never for themselves. */
    public function manages(User $actor, int $userId, ?string $date = null): bool
    {
        if ($userId === (int) $actor->getKey()) {
            return $this->seesEveryone($actor);
        }

        $managed = $this->managedUserIds($actor, $date);

        return $managed === null || in_array($userId, $managed, true);
    }

    /** May $actor look at this person's records? Their own, their team's, or everyone's. */
    public function sees(User $actor, int $userId, ?string $date = null): bool
    {
        return $userId === (int) $actor->getKey() || $this->manages($actor, $userId, $date);
    }

    /** May $actor run (edit, add members to) this team? Its manager, or anyone who sees everyone. */
    public function runsTeam(User $actor, SalesTeam $team): bool
    {
        return $this->seesEveryone($actor) || (int) $team->manager_user_id === (int) $actor->getKey();
    }
}
