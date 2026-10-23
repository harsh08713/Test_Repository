<?php

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\CRM\Enums\FollowupStatus;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Notifications\LeadsAssignedNotification;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hands leads from one person to another — one lead or many at once — and keeps the whole history.
 *
 * Every transfer is checked on the server, lead by lead, whatever ids the browser sent:
 *
 *  - each lead must be one the person transferring may work (LeadAccess: theirs, their team's or the pool they keep) —
 *    a follow-up on somebody else's lead is not enough — and must still be open;
 *  - the new owner must be an active user of the company, must be the person transferring or someone they manage
 *    (anyone, for a holder of `sales.scope_all`), and must work in each lead's branch;
 *  - a reason is required.
 *
 * **All or nothing**: if any selected lead fails a check, none is moved and the message says which and why — a bulk
 * transfer never half-happens. Each moved lead gets a `lead_transfers` row (from, to, teams, reason, by whom, when), its
 * planned follow-ups go to the new owner with it, and the new owner is told once.
 */
final class LeadTransferService
{
    public const MAX_PER_TRANSFER = 200;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SalesScope $scope,
        private readonly SalesTeamService $teams,
        private readonly LeadAccess $access,
        private readonly LeadService $leads,
    ) {}

    /**
     * @param  list<int|string>  $leadIds
     * @return Collection<int, Lead> the leads, now with their new owner
     *
     * @throws ValidationException keyed `leads`, `to_user_id` or `reason`
     */
    public function transfer(array $leadIds, int $toUserId, string $reason, User $actor, bool $allowConverted = false): Collection
    {
        $reason = trim($reason);
        $ids = array_values(array_unique(array_map('intval', array_filter($leadIds, fn ($id) => is_numeric($id)))));

        if ($ids === []) {
            throw ValidationException::withMessages(['leads' => 'Choose at least one lead to transfer.']);
        }

        if (count($ids) > self::MAX_PER_TRANSFER) {
            throw ValidationException::withMessages(['leads' => 'Transfer at most '.self::MAX_PER_TRANSFER.' leads at a time.']);
        }

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'Please say why the lead is changing hands (3 to 1000 characters).']);
        }

        $staff = $this->teams->staff();

        if (! array_key_exists($toUserId, $staff)) {
            throw ValidationException::withMessages(['to_user_id' => 'Choose an active user of this company.']);
        }

        if ($toUserId !== (int) $actor->getKey() && ! $this->scope->manages($actor, $toUserId)) {
            throw ValidationException::withMessages(['to_user_id' => 'You can hand leads only to yourself or to people on the teams you manage.']);
        }

        $newOwner = User::query()->findOrFail($toUserId);
        $theirBranches = $this->tenant->accessibleLocationIdsFor($newOwner, (int) $this->tenant->activeCompanyId());
        // asked about the actor explicitly, so the answer does not depend on who (if anyone) is signed in around this call
        $ownBranches = $this->tenant->accessibleLocationIdsFor($actor, (int) $this->tenant->activeCompanyId());
        $toTeam = $this->leads->teamOf($toUserId);

        $moved = DB::transaction(function () use ($ids, $toUserId, $reason, $actor, $staff, $theirBranches, $ownBranches, $toTeam, $allowConverted) {
            // The tenant scopes apply: a lead of another company or of a branch the person does not work in is "not found".
            $leads = Lead::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            $problems = [];

            if ($leads->count() !== count($ids)) {
                $problems[] = (count($ids) - $leads->count()).' of the selected leads are not available to you';
            }

            foreach ($leads as $lead) {
                $problem = match (true) {
                    $ownBranches !== null && ! in_array((int) $lead->location_id, $ownBranches, true) => 'is in a branch you do not work in',
                    ! $this->access->works($actor, $lead) => 'is not one of yours to hand over',
                    ! $lead->isOpen() => 'is '.strtolower($lead->stage->label()).', so it no longer changes hands',
                    ! $allowConverted && $lead->isConverted() => 'has become a deal, so it moves with the deal: hand the deal over instead',
                    (int) $lead->assigned_to === $toUserId => "is already with {$staff[$toUserId]}",
                    $theirBranches !== null && ! in_array((int) $lead->location_id, $theirBranches, true) => "is in a branch {$staff[$toUserId]} does not work in",
                    default => null,
                };

                if ($problem !== null) {
                    $problems[] = "{$lead->lead_number} {$problem}";
                }
            }

            if ($problems !== []) {
                $shown = array_slice($problems, 0, 5);
                $rest = count($problems) - count($shown);

                throw ValidationException::withMessages(['leads' => 'Nothing was transferred: '.implode('; ', $shown).($rest > 0 ? "; and {$rest} more" : '').'.']);
            }

            foreach ($leads as $lead) {
                $from = $lead->assigned_to === null ? null : (int) $lead->assigned_to;

                $this->leads->recordTransfer($lead, $from, $toUserId, $lead->sales_team_id, $toTeam, $reason, $actor);
                $lead->forceFill(['assigned_to' => $toUserId, 'sales_team_id' => $toTeam, 'updated_by' => $actor->getKey()])->save();

                // What is planned for the lead goes with it, so the reminders reach the person who now has to act.
                Followup::query()->where('subject_type', $lead->getMorphClass())->where('subject_id', $lead->getKey())
                    ->where('status', FollowupStatus::Pending->value)
                    ->when($from !== null, fn ($q) => $q->where('assigned_to', $from))
                    ->update(['assigned_to' => $toUserId, 'reminder_sent_at' => null, 'updated_by' => $actor->getKey(), 'updated_at' => now()]);
            }

            return $leads;
        });

        if ($toUserId !== (int) $actor->getKey()) {
            $first = $moved->first();
            $url = $moved->count() === 1 ? route('crm.leads.show', $first, false) : route('crm.leads.index', [], false);

            $newOwner->notify(new LeadsAssignedNotification($moved->count(), $first->title(), $actor->name, $url));
        }

        return $moved->map(fn (Lead $lead) => $lead->refresh());
    }
}
