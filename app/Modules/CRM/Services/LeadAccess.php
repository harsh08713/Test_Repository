<?php

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\CRM\Enums\FollowupStatus;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Whose leads a person may see and work — the one rule the policy, the lists, the transfer service and (later) the API share.
 *
 * **Work** (edit, move between stages, hand over):
 *  - a lead assigned to somebody: that person, the people who manage them, and holders of `sales.scope_all` (SalesScope);
 *  - a lead assigned to nobody (the pool): whoever entered it, and anyone who may hand leads out (`lead.assign`).
 *
 * **See** (open it, follow it up): everyone who may work it, plus anyone who has a *planned* follow-up on it — a
 * tele-caller asked to ring somebody else's lead has to be able to open it. That reach lasts as long as the follow-up is
 * planned and no longer. And an approver may open an inquiry from an existing customer that is waiting for their decision
 * (or that they have decided): ApproverReach.
 *
 * The company and branch boundaries are not decided here: every lead and follow-up query already runs through the tenant
 * scopes, so a follow-up never opens a lead in a branch the person does not work in.
 */
final class LeadAccess
{
    public function __construct(private readonly SalesScope $scope, private readonly ApproverReach $approvers) {}

    public function sees(User $user, Lead $lead): bool
    {
        return $this->works($user, $lead) || $this->hasPlannedFollowup($user, $lead) || $this->approvesIt($user, $lead);
    }

    /** An inquiry the person is asked to decide, or has decided. */
    private function approvesIt(User $user, Lead $lead): bool
    {
        return $lead->isExisting() && $this->approvers->involves($user, $lead, 'lead.approve');
    }

    /** May $user change this lead: it is theirs, their team's, or in the pool they keep? */
    public function works(User $user, Lead $lead): bool
    {
        if ($lead->assigned_to !== null) {
            return $this->scope->sees($user, (int) $lead->assigned_to);
        }

        return (int) $lead->created_by === (int) $user->getKey() || $user->can('lead.assign') || $this->scope->seesEveryone($user);
    }

    /**
     * Which of these leads $user may open — the answer for a whole page of rows in two queries, where asking about each
     * row would take several apiece. Same rule as sees().
     *
     * @param  iterable<Lead>  $leads
     * @return array<int, true> lead id => true
     */
    public function openable(User $user, iterable $leads): array
    {
        $visible = $this->scope->visibleUserIds($user);
        $pool = $visible === null || $user->can('lead.assign');
        $open = [];
        $rest = [];
        $byId = [];
        $morph = (new Lead)->getMorphClass();

        foreach ($leads as $lead) {
            $byId[(int) $lead->getKey()] = $lead;
            $works = $lead->assigned_to !== null
                ? ($visible === null || in_array((int) $lead->assigned_to, $visible, true))
                : ($pool || (int) $lead->created_by === (int) $user->getKey());

            if ($works) {
                $open[(int) $lead->getKey()] = true;
            } else {
                $rest[] = (int) $lead->getKey();
            }
        }

        if ($rest !== []) {
            $planned = Followup::query()->where('subject_type', $morph)->whereIn('subject_id', $rest)
                ->where('assigned_to', $user->getKey())->pending()->distinct()->pluck('subject_id');

            foreach ($planned as $id) {
                $open[(int) $id] = true;
            }

            $inquiries = [];

            foreach ($rest as $id) {
                if (! isset($open[$id]) && $byId[$id]->isExisting()) {
                    $inquiries[] = $byId[$id];
                }
            }

            foreach (array_keys($this->approvers->openable($user, $inquiries, 'lead.approve')) as $id) {
                $open[$id] = true;
            }
        }

        return $open;
    }

    /** May $user manage this lead on someone's behalf (hand it over, delete it)? Its owner's manager, or the pool's keepers. */
    public function manages(User $user, Lead $lead): bool
    {
        if ($lead->assigned_to !== null) {
            return $this->scope->manages($user, (int) $lead->assigned_to) || (int) $lead->assigned_to === (int) $user->getKey();
        }

        return $user->can('lead.assign');
    }

    /**
     * Limit a lead query to what $user may see.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    public function restrict(Builder $query, User $user): Builder
    {
        $visible = $this->scope->visibleUserIds($user);

        if ($visible === null) {
            return $query;   // everyone's, the pool included
        }

        $pool = $user->can('lead.assign');
        $leads = $query->getModel()->getTable();
        $morph = $query->getModel()->getMorphClass();

        return $query->where(function (Builder $q) use ($visible, $pool, $user, $leads, $morph) {
            $q->whereIn("{$leads}.assigned_to", $visible);

            $pool
                ? $q->orWhereNull("{$leads}.assigned_to")
                : $q->orWhere(fn (Builder $own) => $own->whereNull("{$leads}.assigned_to")->where("{$leads}.created_by", $user->getKey()));

            // …and the leads this person has been asked to follow up
            $q->orWhereExists(fn (QueryBuilder $planned) => $planned->selectRaw('1')->from('followups')
                ->whereColumn('followups.subject_id', "{$leads}.id")
                ->where('followups.subject_type', $morph)
                ->where('followups.assigned_to', $user->getKey())
                ->where('followups.status', FollowupStatus::Pending->value)
                ->whereNull('followups.deleted_at'));

            // …and the inquiries it falls to this person to approve
            $this->approvers->widen($q, $user, 'lead.approve');
        });
    }

    private function hasPlannedFollowup(User $user, Lead $lead): bool
    {
        return Followup::query()
            ->where('subject_type', $lead->getMorphClass())->where('subject_id', $lead->getKey())
            ->where('assigned_to', $user->getKey())
            ->pending()
            ->exists();
    }
}
