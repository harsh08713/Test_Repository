<?php

namespace App\Modules\CRM\Http\Livewire\Leads;

use App\Modules\CRM\Enums\FollowupType;
use App\Modules\CRM\Enums\LeadStage;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\FollowupOutcome;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LostReason;
use App\Modules\CRM\Models\Territory;
use App\Modules\CRM\Services\DealService;
use App\Modules\CRM\Services\FollowupService;
use App\Modules\CRM\Services\LeadAccess;
use App\Modules\CRM\Services\LeadAdvisor;
use App\Modules\CRM\Services\LeadScorer;
use App\Modules\CRM\Services\LeadService;
use App\Modules\CRM\Services\LeadTransferService;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\CRM\Services\SalesTeamService;
use App\Modules\CRM\Services\TerritoryResolver;
use App\Modules\CRM\Support\LeadAdvice;
use App\Modules\CRM\Support\LeadScore;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Services\QuotationAccess;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Services\ApprovalService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One lead: who they are, where it stands, what has been done and what is planned — with the controls to move it between
 * stages, follow it up, hand it over and see the whole history of each.
 *
 * Every action authorises against the lead (LeadPolicy) or the follow-up (FollowupPolicy) it touches, and re-reads it
 * through the tenant scopes first; the rules themselves live in LeadService, FollowupService and LeadTransferService. The
 * follow-up a dialog is about arrives as an id from the browser and is looked up again *within this lead* before use.
 */
#[Title('Lead')]
class LeadShow extends Component
{
    use ScopesToPeople;

    private const DIALOGS = ['lost', 'reopen', 'transfer', 'plan', 'log', 'done', 'move', 'calloff', 'reopen-approval'];

    #[Locked]
    public int $leadId = 0;

    /** Which dialog is open: '' or one of DIALOGS. */
    public string $dialog = '';

    /** The follow-up the open dialog is about. */
    public ?int $followupId = null;

    /** @var array<string, string> a follow-up being planned: scheduled_at, type, assigned_to, notes */
    public array $plan = [];

    /** @var array<string, string> what came of a planned follow-up: outcome_id, notes, next_at, next_type */
    public array $done = [];

    /** @var array<string, string> something just done without a plan: type, outcome_id, notes, next_at, next_type */
    public array $log = [];

    public string $when = '';

    public string $reason = '';

    public string $comment = '';

    public string $lostReasonId = '';

    public string $reopenTo = 'new';

    public string $transferTo = '';

    public function mount(Lead $lead): void
    {
        Gate::authorize('view', $lead);

        $this->leadId = $lead->id;
    }

    #[Computed]
    public function leadModel(): Lead
    {
        $lead = Lead::query()
            ->with(['source:id,name', 'assignee:id,name', 'team:id,name', 'location:id,name,code', 'state:id,name', 'district:id,name', 'block:id,name',
                'lostReason:id,name', 'customer', 'interests.item:id,code,name', 'interests.unit:id,code,name'])
            ->findOrFail($this->leadId);
        Gate::authorize('view', $lead);

        return $lead;
    }

    #[On('approval-changed')]
    public function refreshAfterApproval(): void
    {
        unset($this->leadModel);
    }

    /** Take an approved inquiry back to draft to correct it. */
    public function reopenApproval(ApprovalService $approvals): void
    {
        $lead = $this->leadModel();
        Gate::authorize('reopenApproval', $lead);

        $this->resetErrorBag();
        $this->validate(
            ['reason' => ['required', 'string', 'min:3', 'max:1000']],
            ['reason.required' => 'Please say why you are re-opening this inquiry.', 'reason.min' => 'Please say why you are re-opening this inquiry.'],
        );

        try {
            $approvals->reopen($lead, $this->actor(), $this->reason);
        } catch (ApprovalStateException $e) {
            $this->addError('reason', $e->getMessage());

            return;
        }

        session()->flash('status', 'The inquiry is open for editing again. It cannot be quoted until it is approved again.');
        $this->redirectRoute('crm.leads.edit-inquiry', $lead, navigate: false);
    }

    // ---- stages ---------------------------------------------------------------------------------------------

    /** A plain move between New, Contacted and Qualified. Lost and re-opening have their own dialogs (they need a reason). */
    public function moveToStage(string $stage, LeadService $service): void
    {
        $lead = $this->leadModel();
        Gate::authorize('changeStage', $lead);

        $to = LeadStage::tryFrom($stage);
        abort_if($to === null || $to === LeadStage::Lost || $lead->stage === LeadStage::Lost, 404);

        $this->run(fn () => $service->changeStage($lead, $to, $this->actor()), "The lead is now {$to->label()}.");
    }

    public function markLost(LeadService $service): void
    {
        $lead = $this->leadModel();
        Gate::authorize('changeStage', $lead);

        $this->run(
            fn () => $service->changeStage($lead, LeadStage::Lost, $this->actor(), $this->comment, ctype_digit($this->lostReasonId) ? (int) $this->lostReasonId : null),
            'The lead is marked as lost. What was planned for it has been called off.',
        );
    }

    public function reopen(LeadService $service): void
    {
        $lead = $this->leadModel();
        Gate::authorize('changeStage', $lead);

        $to = LeadStage::tryFrom($this->reopenTo);
        abort_if($to === null || $to === LeadStage::Lost, 404);

        $this->run(fn () => $service->changeStage($lead, $to, $this->actor(), $this->comment), "The lead is open again, as {$to->label()}.");
    }

    // ---- ownership ------------------------------------------------------------------------------------------

    public function transfer(LeadTransferService $service): void
    {
        $lead = $this->leadModel();
        Gate::authorize('transfer', $lead);

        if (! ctype_digit($this->transferTo)) {
            $this->resetErrorBag();
            $this->addError('to_user_id', 'Choose who the lead goes to.');

            return;
        }

        $this->run(fn () => $service->transfer([$lead->getKey()], (int) $this->transferTo, $this->reason, $this->actor()), 'The lead has been transferred. The new owner has been told.');
    }

    public function delete(LeadService $service): void
    {
        $lead = $this->leadModel();
        Gate::authorize('delete', $lead);

        try {
            $service->delete($lead, $this->actor());
        } catch (FieldRuleException $e) {
            $this->addError('workflow', $e->getMessage());

            return;
        }

        session()->flash('status', "Lead {$lead->lead_number} deleted.");
        $this->redirectRoute('crm.leads.index', navigate: false);
    }

    // ---- follow-ups -----------------------------------------------------------------------------------------

    public function planFollowup(FollowupService $service): void
    {
        $lead = $this->leadModel();
        Gate::authorize('followUp', $lead);

        $this->run(fn () => $service->schedule($lead, ['plan' => $this->plan], $this->actor()), 'Follow-up planned.', 'crm.followups.index');
    }

    public function logFollowup(FollowupService $service): void
    {
        $lead = $this->leadModel();
        Gate::authorize('followUp', $lead);

        $this->run(fn () => $service->log($lead, ['log' => $this->log], $this->actor()), 'Recorded.', 'crm.followups.index');
    }

    public function completeFollowup(FollowupService $service): void
    {
        $followup = $this->followup();

        $this->run(fn () => $service->complete($followup, ['done' => $this->done], $this->actor()), 'Follow-up recorded as done.', 'crm.followups.index');
    }

    public function moveFollowup(FollowupService $service): void
    {
        $followup = $this->followup();

        $this->run(fn () => $service->reschedule($followup, $this->when, $this->actor()), 'Follow-up moved.', 'crm.followups.index');
    }

    public function callOffFollowup(FollowupService $service): void
    {
        $followup = $this->followup();

        $this->run(fn () => $service->cancel($followup, $this->reason, $this->actor()), 'Follow-up called off.', 'crm.followups.index');
    }

    /** The follow-up the open dialog is about: one of *this* lead's, and one the person may act on. */
    private function followup(): Followup
    {
        $lead = $this->leadModel();

        $followup = Followup::query()
            ->where('subject_type', $lead->getMorphClass())->where('subject_id', $lead->getKey())
            ->findOrFail((int) $this->followupId);

        Gate::authorize('update', $followup);

        return $followup;
    }

    // ---- dialogs --------------------------------------------------------------------------------------------

    public function openDialog(string $which, ?int $followupId = null): void
    {
        abort_unless(in_array($which, self::DIALOGS, true), 404);

        $lead = $this->leadModel();
        $this->closeDialog();

        if (in_array($which, ['lost', 'reopen'], true)) {
            Gate::authorize('changeStage', $lead);
        } elseif ($which === 'reopen-approval') {
            Gate::authorize('reopenApproval', $lead);
        } elseif ($which === 'transfer') {
            Gate::authorize('transfer', $lead);
        } elseif (in_array($which, ['plan', 'log'], true)) {
            Gate::authorize('followUp', $lead);

            // the lead's owner does the follow-up unless someone else is chosen; a pool lead's falls to whoever plans it
            $owner = (string) ($lead->assigned_to ?? $this->actor()->getKey());
            $this->plan = ['scheduled_at' => '', 'type' => FollowupType::Call->value, 'assigned_to' => $owner, 'notes' => ''];
            $this->log = ['type' => FollowupType::Call->value, 'outcome_id' => '', 'notes' => '', 'next_at' => '', 'next_type' => ''];
        } else {
            $this->followupId = $followupId;
            $followup = $this->followup();   // 404 if it is not this lead's, 403 if it is not theirs to act on

            $this->done = ['outcome_id' => '', 'notes' => '', 'next_at' => '', 'next_type' => $followup->type->value];
            $this->when = $followup->scheduled_at->format('Y-m-d\TH:i');
        }

        $this->dialog = $which;
    }

    public function closeDialog(): void
    {
        $this->dialog = '';
        $this->followupId = null;
        $this->plan = $this->done = $this->log = [];
        $this->when = $this->reason = $this->comment = $this->lostReasonId = $this->transferTo = '';
        $this->reopenTo = LeadStage::New->value;
        $this->resetErrorBag();
    }

    /**
     * Run a service call and show what it says: a rule that was broken above the page, a field that needs attention in
     * the open dialog. On success the page is loaded afresh — unless the lead is no longer one this person can open (it
     * was handed to someone else, or the follow-up that let them see it is finished), in which case they go to $fallback.
     *
     * @param  callable(): mixed  $action
     */
    private function run(callable $action, string $done, string $fallback = 'crm.leads.index'): void
    {
        $this->resetErrorBag();

        try {
            $action();
        } catch (FieldRuleException $e) {
            $this->addError('workflow', $e->getMessage());

            return;
        } catch (ValidationException $e) {
            // the services name their fields the way the dialogs do (plan.type, done.outcome_id, reason, when…)
            $this->setErrorBag($e->validator->errors());

            return;
        }

        unset($this->leadModel);
        $this->closeDialog();
        session()->flash('status', $done);

        $lead = Lead::query()->find($this->leadId);

        $lead !== null && Gate::allows('view', $lead)
            ? $this->redirectRoute('crm.leads.show', $this->leadId, navigate: false)
            : $this->redirectRoute($fallback, navigate: false);
    }

    /**
     * The lead's score, the reasons for it and what is worth doing next — or null when the company has switched this off. Anyone who can open the lead sees it (the
     * score is made of what is on the page already).
     *
     * @return array{leadScore: LeadScore|null, leadAdvice: LeadAdvice|null}
     */
    private function leadAdvice(Lead $lead): array
    {
        $scorer = app(LeadScorer::class);
        $config = $scorer->config((int) $lead->company_id);

        if (! $config->enabled) {
            return ['leadScore' => null, 'leadAdvice' => null];
        }

        $facts = $scorer->facts($lead);
        $score = $scorer->score($lead, $facts, $config);

        return ['leadScore' => $score, 'leadAdvice' => app(LeadAdvisor::class)->advise($lead, $score, $facts)];
    }

    /** Which territory covers this lead and who owns it — advice for whoever hands the lead out; shown only to someone who may see territories. */
    private function territoryNote(Lead $lead): ?string
    {
        if (! Gate::allows('viewAny', Territory::class)) {
            return null;
        }

        $match = app(TerritoryResolver::class)->forLead($lead);

        return $match === null
            ? null
            : "{$match['territory']->name} — ".($match['territory']->owner->name ?? 'no owner yet')." (by {$match['kind']->label()})";
    }

    public function render(FollowupService $followupService, SalesTeamService $teams, SalesScope $scope, DealService $deals): View
    {
        $lead = $this->leadModel();
        $actor = $this->actor();
        $deal = $lead->isConverted() || $lead->stage === LeadStage::Lost ? $deals->dealOf($lead) : null;

        $followups = $lead->followups()->with(['assignee:id,name', 'outcome:id,name', 'completer:id,name'])->limit(100)->get();
        $canStage = Gate::allows('changeStage', $lead);
        $canTransfer = Gate::allows('transfer', $lead);
        $canFollowUp = Gate::allows('followUp', $lead);

        // who the lead can go to: the person's own reach, among the people who work in the lead's branch, bar its owner
        $targets = [];
        if ($canTransfer && $this->dialog === 'transfer') {
            $managed = $scope->managedUserIds($actor);
            $targets = $teams->staffInBranch((int) $lead->location_id);
            $targets = $managed === null ? $targets : array_intersect_key($targets, array_flip([...$managed, (int) $actor->getKey()]));
            unset($targets[(int) $lead->assigned_to]);
        }

        return view('crm.leads.show', [
            'lead' => $lead,
            'planned' => $followups->filter(fn (Followup $f) => $f->isPending())->sortBy('scheduled_at')->values(),
            'past' => $followups->reject(fn (Followup $f) => $f->isPending())->values(),
            'actionable' => $followups->filter(fn (Followup $f) => $f->isPending() && Gate::allows('update', $f))->pluck('id')->flip()->all(),
            'transfers' => $lead->transfers()->with(['from:id,name', 'to:id,name', 'actor:id,name'])->limit(50)->get(),
            'stages' => $lead->stageHistory()->with(['actor:id,name', 'lostReason:id,name'])->limit(50)->get(),
            'history' => Gate::allows('viewAudit', $lead)
                ? ActivityLog::query()->with('causer')->where('subject_type', $lead->getMorphClass())->where('subject_id', $lead->id)->latest('id')->limit(30)->get()
                : collect(),
            'canEdit' => Gate::allows('update', $lead),
            'canStage' => $canStage,
            'canTransfer' => $canTransfer,
            'canFollowUp' => $canFollowUp,
            'canDelete' => Gate::allows('delete', $lead),
            'canConvert' => Gate::allows('convert', $lead),
            'canReopenApproval' => Gate::allows('reopenApproval', $lead),
            'deal' => $deal,
            // quotations made straight from an approved inquiry (once it has a deal, its quotations are the deal's)
            'quotations' => $lead->isExisting() && $deal === null
                ? app(QuotationAccess::class)->restrict(Quotation::query()->where('lead_id', $lead->getKey())->whereNull('deal_id'), $actor)->orderByDesc('id')->limit(30)->get()
                : collect(),
            'canQuote' => $deal === null && $lead->isReadyForQuotation() && Gate::allows('create', Quotation::class) && app(LeadAccess::class)->works($actor, $lead),
            'canViewDeal' => $deal !== null && Gate::allows('view', $deal),
            'canViewCustomer' => $lead->customer !== null && Gate::allows('view', $lead->customer),
            'territoryNote' => $this->territoryNote($lead),
            ...$this->leadAdvice($lead),
            // the plain moves offered as buttons; Lost and re-opening are dialogs
            'moves' => $canStage && $lead->stage !== LeadStage::Lost ? array_values(array_filter($lead->stage->manualMoves(), fn (LeadStage $s) => $s !== LeadStage::Lost)) : [],
            'reopenStages' => $lead->stage === LeadStage::Lost ? $lead->stage->manualMoves() : [],
            'types' => FollowupType::cases(),
            'outcomes' => $this->dialog === 'done' || $this->dialog === 'log' ? FollowupOutcome::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'needs_next_followup']) : collect(),
            'lostReasons' => $this->dialog === 'lost' ? LostReason::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all() : [],
            'assignable' => $this->dialog === 'plan' ? $followupService->assignable($lead, $actor) : [],
            'transferTargets' => $targets,
            'target' => $this->followupId === null ? null : $followups->firstWhere('id', $this->followupId),
            'now' => now(),
        ]);
    }
}
