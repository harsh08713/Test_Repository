<?php

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\CRM\Models\Deal;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whose deals a person may see and work — the one rule the policy, the lists, the reassignment and (later) the API share.
 *
 * A deal always has an owner (the lead's owner when it was converted), so there is no pool:
 *  - **Work** (change it, move its stage, hand it over): its owner, the people who manage them, and holders of
 *    `sales.scope_all` (SalesScope).
 *  - **See** (open it): everyone who may work it, and an approver for a deal waiting for their decision or one they have
 *    decided (ApproverReach).
 *
 * The company and branch boundaries are not decided here: every deal query already runs through the tenant scopes.
 */
final class DealAccess
{
    public function __construct(private readonly SalesScope $scope, private readonly ApproverReach $approvers) {}

    public function sees(User $user, Deal $deal): bool
    {
        return $this->works($user, $deal) || $this->approvers->involves($user, $deal, 'deal.approve');
    }

    /** May $user change this deal: it is theirs, their team's, or they see everyone's? */
    public function works(User $user, Deal $deal): bool
    {
        return $this->scope->sees($user, (int) $deal->assigned_to);
    }

    /** May $user manage this deal on its owner's behalf (hand it over)? Its owner's manager, or the owner. */
    public function manages(User $user, Deal $deal): bool
    {
        return $this->scope->manages($user, (int) $deal->assigned_to) || (int) $deal->assigned_to === (int) $user->getKey();
    }

    /**
     * Which of these deals $user may open — the answer for a page of rows in one or two queries. Same rule as sees().
     *
     * @param  iterable<Deal>  $deals
     * @return array<int, true> deal id => true
     */
    public function openable(User $user, iterable $deals): array
    {
        $visible = $this->scope->visibleUserIds($user);
        $open = [];
        $rest = [];

        foreach ($deals as $deal) {
            if ($visible === null || in_array((int) $deal->assigned_to, $visible, true)) {
                $open[(int) $deal->getKey()] = true;
            } else {
                $rest[] = $deal;
            }
        }

        foreach (array_keys($this->approvers->openable($user, $rest, 'deal.approve')) as $id) {
            $open[$id] = true;
        }

        return $open;
    }

    /**
     * Limit a deal query to what $user may see.
     *
     * @param  Builder<Deal>  $query
     * @return Builder<Deal>
     */
    public function restrict(Builder $query, User $user): Builder
    {
        $visible = $this->scope->visibleUserIds($user);

        if ($visible === null) {
            return $query;   // everyone's
        }

        $deals = $query->getModel()->getTable();

        return $query->where(function (Builder $q) use ($visible, $user, $deals) {
            $q->whereIn("{$deals}.assigned_to", $visible);

            // …and the deals it falls to this person to approve
            $this->approvers->widen($q, $user, 'deal.approve');
        });
    }
}
