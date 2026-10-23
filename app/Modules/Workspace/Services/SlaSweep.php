<?php

namespace App\Modules\Workspace\Services;

use App\Models\User;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\SalesTeam;
use App\Modules\CRM\Models\SalesTeamMember;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Shared\Contracts\ApprovableDocument;
use App\Modules\Shared\Models\Approval;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Support\Format;
use App\Modules\Shared\Tenancy\TenantContext;
use App\Modules\Workspace\Enums\SlaKind;
use App\Modules\Workspace\Models\SlaBreach;
use App\Modules\Workspace\Models\SlaClock;
use App\Modules\Workspace\Models\SlaPolicy;
use App\Modules\Workspace\Models\Task;
use App\Modules\Workspace\Notifications\SlaBreachedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The SLA engine. One pass (`sla:check`, every five minutes) does four things for every company, and every step is safe to repeat:
 *
 *  1. **Start** a clock for each lead, planned follow-up and task that a policy covers and that has none.
 *  2. **Settle** the running clocks from the facts: a lead that has been contacted, a follow-up that was done, a task that was finished have
 *     *met* theirs (at the moment it happened, so being late shows even if the sweep ran later); a lead that was won or lost, a follow-up or
 *     task called off or moved, a lead handed to somebody else have *stopped* theirs — and a handed-over lead gets a fresh clock.
 *  3. **Notice** the clocks that ran out: claim the breach with one conditional UPDATE, write it in the breach log, tell the person (and
 *     their manager when the policy escalates) once, and leave an audit entry.
 *
 * Nothing is reassigned automatically: an escalation tells a manager, it does not move work. Facts are read, never trusted from the screens, so a
 * change made by an import, the API or a script is settled the same way.
 */
final class SlaSweep
{
    private const BATCH = 2000;

    /** Things older than this are not given a clock when a sweep finds them without one (a policy is not retroactive anyway). */
    private const START_WINDOW_DAYS = 30;

    /** @var array<string, int|null> */
    private array $teamCache = [];

    public function __construct(private readonly SlaPolicies $policies, private readonly BusinessClock $clock, private readonly TenantContext $tenant) {}

    /** @return array{started: int, settled: int, breached: int} */
    public function run(?Carbon $now = null): array
    {
        $now ??= now();
        $this->policies->forget();
        $this->teamCache = [];

        return $this->tenant->withoutTenancy(function () use ($now) {
            $started = $this->startLeadClocks($now) + $this->startFollowupClocks($now) + $this->startTaskClocks($now) + $this->startQuotationClocks($now) + $this->startApprovalClocks($now);
            $settled = $this->settleLeadClocks($now) + $this->settleFollowupClocks($now) + $this->settleTaskClocks($now) + $this->settleQuotationClocks($now) + $this->settleApprovalClocks($now);
            $started += $this->startLeadClocks($now);   // a handed-over lead has just had its old clock stopped: start the new one now

            return ['started' => $started, 'settled' => $settled, 'breached' => $this->noticeBreaches($now)];
        });
    }

    // ---- 1. start ------------------------------------------------------------------------------------------------------------

    private function startLeadClocks(Carbon $now): int
    {
        $count = 0;
        $leads = Lead::withoutGlobalScopes(Tenancy::SCOPES)->where('stage', 'new')->whereNull('last_contacted_at')
            ->where('created_at', '>=', $now->copy()->subDays(self::START_WINDOW_DAYS))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('sla_clocks')->whereColumn('sla_clocks.subject_id', 'leads.id')
                ->where('sla_clocks.subject_type', 'lead')->where('sla_clocks.kind', SlaKind::FirstResponse->value)->where('sla_clocks.state', 'running'))
            ->orderBy('id')->limit(self::BATCH)->get();

        foreach ($leads as $lead) {
            $companyId = (int) $lead->company_id;
            // a lead handed over starts again from the hand-over; otherwise from the moment it was made
            $from = $this->lastTransferAt($lead) ?? $lead->created_at;
            $policy = $this->policies->find($companyId, SlaKind::FirstResponse, $lead->lead_source_id, $lead->sales_team_id, null, $lead->created_at);

            if ($policy === null || ! $this->noClockAt('lead', (int) $lead->getKey(), SlaKind::FirstResponse, $from)) {
                continue;
            }

            $this->open($policy, 'lead', (int) $lead->getKey(), $lead->assigned_to === null ? null : (int) $lead->assigned_to, $from) && $count++;
        }

        return $count;
    }

    private function startFollowupClocks(Carbon $now): int
    {
        $count = 0;
        $followups = Followup::withoutGlobalScopes(Tenancy::SCOPES)->where('status', 'pending')
            ->where('scheduled_at', '>=', $now->copy()->subDays(self::START_WINDOW_DAYS))
            ->orderBy('id')->limit(self::BATCH)->get();

        foreach ($followups as $followup) {
            $companyId = (int) $followup->company_id;
            $source = $followup->subject_type === 'lead' ? Lead::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($followup->subject_id)->value('lead_source_id') : null;
            $policy = $this->policies->find($companyId, SlaKind::Followup, $source === null ? null : (int) $source, $this->teamOf((int) $followup->assigned_to, $companyId, $now), null, $followup->created_at);

            if ($policy === null || ! $this->noClockAt('followup', (int) $followup->getKey(), SlaKind::Followup, $followup->scheduled_at)) {
                continue;
            }

            $this->open($policy, 'followup', (int) $followup->getKey(), (int) $followup->assigned_to, $followup->scheduled_at) && $count++;
        }

        return $count;
    }

    private function startTaskClocks(Carbon $now): int
    {
        $count = 0;
        $tasks = Task::withoutGlobalScopes(Tenancy::SCOPES)->whereIn('status', ['open', 'in_progress'])->whereNotNull('due_at')
            ->where('due_at', '>=', $now->copy()->subDays(self::START_WINDOW_DAYS))
            ->orderBy('id')->limit(self::BATCH)->get();

        foreach ($tasks as $task) {
            $companyId = (int) $task->company_id;
            $policy = $this->policies->find($companyId, SlaKind::Task, null, $this->teamOf((int) $task->assigned_to, $companyId, $now), $task->priority->value, $task->created_at);

            if ($policy === null || $task->due_at === null || ! $this->noClockAt('task', (int) $task->getKey(), SlaKind::Task, $task->due_at)) {
                continue;
            }

            $this->open($policy, 'task', (int) $task->getKey(), (int) $task->assigned_to, $task->due_at) && $count++;
        }

        return $count;
    }

    private function startQuotationClocks(Carbon $now): int
    {
        $count = 0;
        $quotations = Quotation::withoutGlobalScopes(Tenancy::SCOPES)->where('state', 'sent')->whereNotNull('sent_at')
            ->where('sent_at', '>=', $now->copy()->subDays(self::START_WINDOW_DAYS))->orderBy('id')->limit(self::BATCH)->get();

        foreach ($quotations as $quotation) {
            $companyId = (int) $quotation->company_id;
            $owner = $this->quotationOwner($quotation);
            $policy = $this->policies->find($companyId, SlaKind::QuotationResponse, null, $owner === null ? null : $this->teamOf($owner, $companyId, $now), null, $quotation->created_at);

            if ($policy === null || ! $this->noClockAt('quotation', (int) $quotation->getKey(), SlaKind::QuotationResponse, $quotation->sent_at)) {
                continue;
            }

            $this->open($policy, 'quotation', (int) $quotation->getKey(), $owner, $quotation->sent_at) && $count++;
        }

        return $count;
    }

    /** Only the level that is waiting for its turn is timed (all of them for a parallel approval), from the moment it was its turn. */
    private function startApprovalClocks(Carbon $now): int
    {
        $count = 0;
        $levels = Approval::withoutGlobalScopes(Tenancy::SCOPES)->where('status', 'pending')->where('created_at', '>=', $now->copy()->subDays(self::START_WINDOW_DAYS))
            ->where(fn ($q) => $q->where('is_parallel', true)->orWhereRaw('level = (select min(a2.level) from approvals a2 where a2.approvable_id = approvals.approvable_id and a2.approvable_type = approvals.approvable_type and a2.cycle = approvals.cycle and a2.status = \'pending\')'))
            ->orderBy('id')->limit(self::BATCH)->get();

        foreach ($levels as $level) {
            $companyId = (int) $level->company_id;
            $from = $level->is_parallel ? $level->created_at : (Approval::withoutGlobalScopes(Tenancy::SCOPES)->where('approvable_id', $level->approvable_id)->where('approvable_type', $level->approvable_type)
                ->where('cycle', $level->cycle)->where('level', '<', $level->level)->max('acted_at') ?: $level->created_at);
            $from = Carbon::parse($from);
            $owner = $level->approver_user_id === null ? null : (int) $level->approver_user_id;
            $policy = $this->policies->find($companyId, SlaKind::Approval, null, $owner === null ? null : $this->teamOf($owner, $companyId, $now), null, $level->created_at);

            if ($policy === null || ! $this->noClockAt('approval', (int) $level->getKey(), SlaKind::Approval, $from)) {
                continue;
            }

            $this->open($policy, 'approval', (int) $level->getKey(), $owner, $from) && $count++;
        }

        return $count;
    }

    /** Start the clock; false when another sweep got there first (the unique-ish check is repeated under the insert). */
    private function open(SlaPolicy $policy, string $type, int $id, ?int $ownerId, Carbon $from): bool
    {
        $due = $this->clock->after($from, $policy->target_minutes, $policy->business_hours, (int) $policy->company_id);
        $kind = $policy->kind;

        return DB::transaction(function () use ($policy, $type, $id, $ownerId, $from, $due, $kind) {
            // two sweeps at once: lock the policy row so only one of them inserts the clock for this moment
            SlaPolicy::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($policy->getKey())->lockForUpdate()->first();

            if (! $this->noClockAt($type, $id, $kind, $from)) {
                return false;
            }

            $clock = new SlaClock;
            $clock->forceFill([
                'company_id' => $policy->company_id, 'sla_policy_id' => $policy->getKey(), 'kind' => $kind->value, 'subject_type' => $type, 'subject_id' => $id,
                'owner_id' => $ownerId, 'started_at' => $from, 'due_at' => $due, 'state' => 'running',
            ])->save();

            return true;
        });
    }

    private function noClockAt(string $type, int $id, SlaKind $kind, Carbon $startedAt): bool
    {
        return ! SlaClock::withoutGlobalScopes(Tenancy::SCOPES)->where('subject_type', $type)->where('subject_id', $id)
            ->where('kind', $kind->value)->where('started_at', $startedAt->format('Y-m-d H:i:s'))->exists();
    }

    // ---- 2. settle -----------------------------------------------------------------------------------------------------------

    private function settleLeadClocks(Carbon $now): int
    {
        $count = 0;

        foreach ($this->running(SlaKind::FirstResponse) as $clock) {
            $lead = Lead::withoutGlobalScopes(Tenancy::SCOPES)->withTrashed()->whereKey($clock->subject_id)->first();

            if ($lead === null || $lead->trashed()) {
                $count += $this->stop($clock, 'stopped', 'closed', $now);
            } elseif ($lead->last_contacted_at !== null) {
                $count += $this->meet($clock, $lead->last_contacted_at);
            } elseif ($lead->stage->value !== 'new') {
                $count += in_array($lead->stage->value, ['won', 'lost'], true)
                    ? $this->stop($clock, 'stopped', 'closed', $now)
                    : $this->meet($clock, $this->leftNewAt($lead) ?? $now);
            } elseif ($lead->assigned_to !== null && (int) $lead->assigned_to !== (int) $clock->owner_id) {
                $count += $this->stop($clock, 'stopped', 'transferred', $this->lastTransferAt($lead) ?? $now);
            } elseif ($lead->assigned_to === null && $clock->owner_id !== null) {
                $count += $this->stop($clock, 'stopped', 'transferred', $this->lastTransferAt($lead) ?? $now);
            }
        }

        return $count;
    }

    private function settleFollowupClocks(Carbon $now): int
    {
        $count = 0;

        foreach ($this->running(SlaKind::Followup) as $clock) {
            $followup = Followup::withoutGlobalScopes(Tenancy::SCOPES)->withTrashed()->whereKey($clock->subject_id)->first();

            if ($followup === null || $followup->trashed() || $followup->status->value === 'cancelled') {
                $count += $this->stop($clock, 'stopped', 'cancelled', $now);
            } elseif ($followup->status->value === 'done') {
                $count += $this->meet($clock, $followup->completed_at ?? $now);
            } elseif ($followup->scheduled_at->ne($clock->started_at)) {
                $count += $this->stop($clock, 'stopped', 'rescheduled', $now);
            } elseif ((int) $followup->assigned_to !== (int) $clock->owner_id) {
                SlaClock::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($clock->getKey())->where('state', 'running')->update(['owner_id' => $followup->assigned_to]);
            }
        }

        return $count;
    }

    private function settleTaskClocks(Carbon $now): int
    {
        $count = 0;

        foreach ($this->running(SlaKind::Task) as $clock) {
            $task = Task::withoutGlobalScopes(Tenancy::SCOPES)->withTrashed()->whereKey($clock->subject_id)->first();

            if ($task === null || $task->trashed() || $task->status->value === 'cancelled') {
                $count += $this->stop($clock, 'stopped', 'cancelled', $now);
            } elseif ($task->status->value === 'done') {
                $count += $this->meet($clock, $task->completed_at ?? $now);
            } elseif ($task->due_at === null || $task->due_at->ne($clock->started_at)) {
                $count += $this->stop($clock, 'stopped', 'rescheduled', $now);
            } elseif ((int) $task->assigned_to !== (int) $clock->owner_id) {
                SlaClock::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($clock->getKey())->where('state', 'running')->update(['owner_id' => $task->assigned_to]);
            }
        }

        return $count;
    }

    private function settleQuotationClocks(Carbon $now): int
    {
        $count = 0;

        foreach ($this->running(SlaKind::QuotationResponse) as $clock) {
            $quotation = Quotation::withoutGlobalScopes(Tenancy::SCOPES)->withTrashed()->whereKey($clock->subject_id)->first();

            if ($quotation === null || $quotation->trashed()) {
                $count += $this->stop($clock, 'stopped', 'closed', $now);

                continue;
            }

            $state = $quotation->state->value;
            $ordered = SalesOrder::withoutGlobalScopes(Tenancy::SCOPES)->where('quotation_id', $quotation->getKey())->where('state', '<>', 'cancelled')->min('created_at');

            if ($state === 'accepted' || $state === 'declined') {
                $count += $this->meet($clock, ($state === 'accepted' ? $quotation->accepted_at : $quotation->declined_at) ?? $now);
            } elseif ($ordered !== null) {
                $count += $this->meet($clock, Carbon::parse($ordered));
            } elseif ($state !== 'sent') {
                $count += $this->stop($clock, 'stopped', 'closed', $now);   // superseded by a new revision, expired, withdrawn, back to draft
            } elseif ($this->quotationOwner($quotation) !== ($clock->owner_id === null ? null : (int) $clock->owner_id)) {
                SlaClock::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($clock->getKey())->where('state', 'running')->update(['owner_id' => $this->quotationOwner($quotation)]);
            }
        }

        return $count;
    }

    private function settleApprovalClocks(Carbon $now): int
    {
        $count = 0;

        foreach ($this->running(SlaKind::Approval) as $clock) {
            $level = Approval::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($clock->subject_id)->first();

            if ($level === null || $level->status->value === 'cancelled') {
                $count += $this->stop($clock, 'stopped', 'cancelled', $now);
            } elseif ($level->status->value !== 'pending') {
                $count += $this->meet($clock, $level->acted_at ?? $now);
            }
        }

        return $count;
    }

    private function quotationOwner(Quotation $quotation): ?int
    {
        $owner = Lead::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($quotation->lead_id)->value('assigned_to');

        return $owner === null ? null : (int) $owner;
    }

    /** @return Collection<int, SlaClock> */
    private function running(SlaKind $kind): Collection
    {
        return SlaClock::withoutGlobalScopes(Tenancy::SCOPES)->where('state', 'running')->where('kind', $kind->value)->orderBy('id')->limit(self::BATCH)->get();
    }

    /** The thing was answered at $at. If that was after the clock ran out, the breach is recorded (answered late) even if no sweep saw it in time. */
    private function meet(SlaClock $clock, Carbon $at): int
    {
        $won = SlaClock::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($clock->getKey())->where('state', 'running')
            ->update(['state' => 'met', 'stopped_at' => $at, 'stop_reason' => 'responded', 'updated_at' => now()]);

        if ($won === 1 && $at->gt($clock->due_at)) {
            $claimed = SlaClock::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($clock->getKey())->whereNull('breached_at')->update(['breached_at' => $clock->due_at]);

            if ($claimed === 1) {
                $this->logBreach($clock, null, true);
            }
        }

        return $won;
    }

    private function stop(SlaClock $clock, string $state, string $reason, Carbon $at): int
    {
        return SlaClock::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($clock->getKey())->where('state', 'running')
            ->update(['state' => $state, 'stopped_at' => $at, 'stop_reason' => $reason, 'updated_at' => now()]);
    }

    // ---- 3. notice -----------------------------------------------------------------------------------------------------------

    private function noticeBreaches(Carbon $now): int
    {
        $count = 0;
        $clocks = SlaClock::withoutGlobalScopes(Tenancy::SCOPES)->where('state', 'running')->whereNull('breached_at')->whereNull('paused_at')->where('due_at', '<', $now)
            ->orderBy('due_at')->limit(self::BATCH)->get();

        foreach ($clocks as $clock) {
            // claim it first: whoever wins this UPDATE records and announces the breach, and nobody else does
            $claimed = SlaClock::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($clock->getKey())->where('state', 'running')->whereNull('breached_at')->whereNull('paused_at')
                ->update(['breached_at' => $now]);

            if ($claimed !== 1) {
                continue;
            }

            $policy = SlaPolicy::withoutGlobalScopes(Tenancy::SCOPES)->find($clock->sla_policy_id);
            $owner = $clock->owner_id === null ? null : User::query()->where('is_active', true)->find($clock->owner_id);
            $others = $clock->kind === SlaKind::Approval && $owner === null ? $this->approvers($clock) : [];
            $manager = $policy?->escalate_to_manager && $clock->owner_id !== null ? $this->managerOf((int) $clock->owner_id, (int) $clock->company_id, $now) : null;

            $breach = $this->logBreach($clock, $manager?->getKey(), false);
            $about = $this->describe($clock);

            if ($about !== null) {
                $owner?->notify(new SlaBreachedNotification($clock->kind->short(), $about['label'], Format::dateTime($clock->due_at), $about['url']));

                foreach ($others as $other) {
                    $other->notify(new SlaBreachedNotification($clock->kind->short(), $about['label'], Format::dateTime($clock->due_at), $about['url']));
                }

                $manager?->notify(new SlaBreachedNotification($clock->kind->short(), $about['label'], Format::dateTime($clock->due_at), $about['url'], $owner->name ?? 'nobody'));
            }

            $manager !== null && SlaClock::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($clock->getKey())->update(['escalated_at' => $now]);

            activity('sla')->performedOn($clock)->event('breached')
                ->withProperties(['kind' => $clock->kind->value, 'owner_id' => $clock->owner_id, 'escalated_to' => $manager?->getKey(), 'breach_id' => $breach->getKey()])
                ->log('SLA ran out: '.$clock->kind->label());

            $count++;
        }

        return $count;
    }

    private function logBreach(SlaClock $clock, ?int $escalatedTo, bool $late): SlaBreach
    {
        $breach = new SlaBreach;
        $breach->forceFill([
            'company_id' => $clock->company_id, 'sla_clock_id' => $clock->getKey(), 'sla_policy_id' => $clock->sla_policy_id, 'kind' => $clock->kind->value,
            'subject_type' => $clock->subject_type, 'subject_id' => $clock->subject_id, 'owner_id' => $clock->owner_id, 'escalated_to' => $escalatedTo,
            'due_at' => $clock->due_at, 'detected_at' => now(), 'answered_late' => $late,
        ])->save();

        return $breach;
    }

    /** @return array{label: string, url: string}|null */
    private function describe(SlaClock $clock): ?array
    {
        if ($clock->subject_type === 'lead') {
            $lead = Lead::withoutGlobalScopes(Tenancy::SCOPES)->find($clock->subject_id);

            return $lead === null ? null : ['label' => $lead->title(), 'url' => route('crm.leads.show', $lead->getKey(), false)];
        }

        if ($clock->subject_type === 'followup') {
            $followup = Followup::withoutGlobalScopes(Tenancy::SCOPES)->find($clock->subject_id);
            $lead = $followup === null || $followup->subject_type !== 'lead' ? null : Lead::withoutGlobalScopes(Tenancy::SCOPES)->find($followup->subject_id);

            return $followup === null || $lead === null ? null : ['label' => $followup->type->label().' with '.$lead->title(), 'url' => route('crm.leads.show', $lead->getKey(), false)];
        }

        if ($clock->subject_type === 'quotation') {
            $quotation = Quotation::withoutGlobalScopes(Tenancy::SCOPES)->find($clock->subject_id);

            return $quotation === null ? null : ['label' => $quotation->quotation_number.' · '.$quotation->party_name, 'url' => route('sales.quotations.show', $quotation->getKey(), false)];
        }

        if ($clock->subject_type === 'approval') {
            $level = Approval::withoutGlobalScopes(Tenancy::SCOPES)->find($clock->subject_id);
            $document = $level?->approvable;

            return $document instanceof ApprovableDocument ? ['label' => $document->approvalTitle(), 'url' => (string) (parse_url((string) $document->approvalUrl(), PHP_URL_PATH) ?: '/approvals')] : null;
        }

        $task = Task::withoutGlobalScopes(Tenancy::SCOPES)->find($clock->subject_id);

        return $task === null ? null : ['label' => $task->title, 'url' => route('workspace.tasks.show', $task->getKey(), false)];
    }

    /**
     * Who is to decide an approval that was given to a role, not a person: the active members of the company who hold that role.
     *
     * @return list<User>
     */
    private function approvers(SlaClock $clock): array
    {
        $level = Approval::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($clock->subject_id)->first(['approver_role', 'document_type']);

        if ($level === null) {
            return [];
        }

        $members = DB::table('company_user')->where('company_id', $clock->company_id)->select('user_id');

        // a level given to a role goes to its members; one given to nobody in particular goes to everyone who holds the right to approve that kind of document
        $query = User::query()->where('is_active', true)->whereIn('id', $members);
        $level->approver_role !== null ? $query->role((string) $level->approver_role) : $query->permission($level->document_type->value.'.approve');

        return $query->get()->all();
    }

    // ---- who ----------------------------------------------------------------------------------------------------------------

    private function lastTransferAt(Lead $lead): ?Carbon
    {
        $at = DB::table('lead_transfers')->where('lead_id', $lead->getKey())->max('created_at');

        return $at === null ? null : Carbon::parse($at);
    }

    private function leftNewAt(Lead $lead): ?Carbon
    {
        $at = DB::table('lead_stage_history')->where('lead_id', $lead->getKey())->where('from_stage', 'new')->min('created_at');

        return $at === null ? null : Carbon::parse($at);
    }

    private function teamOf(int $userId, int $companyId, Carbon $now): ?int
    {
        return $this->teamCache["t{$companyId}:{$userId}"] ??= SalesTeamMember::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $companyId)->where('user_id', $userId)
            ->where('joined_on', '<=', $now->toDateString())->where(fn ($q) => $q->whereNull('left_on')->orWhere('left_on', '>=', $now->toDateString()))
            ->value('sales_team_id');
    }

    private function managerOf(int $userId, int $companyId, Carbon $now): ?User
    {
        $teamId = $this->teamOf($userId, $companyId, $now);

        if ($teamId === null) {
            return null;
        }

        $managerId = SalesTeam::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $companyId)->where('status', 'active')->whereKey($teamId)->value('manager_user_id');

        return $managerId === null || (int) $managerId === $userId ? null : User::query()->where('is_active', true)->find($managerId);
    }
}
