<?php

namespace App\Modules\CRM\Http\Livewire\Deals;

use App\Modules\CRM\Enums\DealStage;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\LostReason;
use App\Modules\CRM\Services\DealForecaster;
use App\Modules\CRM\Services\DealService;
use App\Modules\CRM\Services\SalesScope;
use App\Modules\CRM\Services\SalesTeamService;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\QuotationAccess;
use App\Modules\Sales\Services\SalesOrderAccess;
use App\Modules\Sales\Services\SalesOrderService;
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
 * One deal: who it is for, what is in it, where it stands and what has been done — with the controls to send it for
 * approval (the shared approval panel), move it between stages, mark it lost or bring it back, change where it stands, and
 * hand it over.
 *
 * Every action authorises against the deal (DealPolicy) and re-reads it through the tenant scopes first; the rules
 * themselves live in DealService. What the lead's own follow-ups say is shown here, but follow-ups are planned and recorded
 * on the lead, which stays open while its deal is.
 */
#[Title('Deal')]
class DealShow extends Component
{
    use ScopesToPeople;

    private const DIALOGS = ['lost', 'reopen-lost', 'reopen-approval', 'reassign', 'progress'];

    #[Locked]
    public int $dealId = 0;

    /** Which dialog is open: '' or one of DIALOGS. */
    public string $dialog = '';

    /** @var array<string, string> what an open deal's progress dialog edits: expected_close_date, probability, remarks */
    public array $progress = [];

    public string $reason = '';

    public string $comment = '';

    public string $lostReasonId = '';

    public string $reopenTo = 'discovery';

    public string $reassignTo = '';

    public function mount(Deal $deal): void
    {
        Gate::authorize('view', $deal);

        $this->dealId = $deal->id;
    }

    #[Computed]
    public function dealModel(): Deal
    {
        $deal = Deal::query()
            // the whole lead, not a column list: the policy that decides whether it can be opened reads its owner, its creator and its approval
            ->with(['lead', 'customer', 'assignee:id,name', 'team:id,name', 'location:id,name,code', 'lostReason:id,name',
                'items.item:id,code,name', 'items.unit:id,code,name'])
            ->findOrFail($this->dealId);
        Gate::authorize('view', $deal);

        return $deal;
    }

    #[On('approval-changed')]
    public function refreshAfterApproval(): void
    {
        unset($this->dealModel);
    }

    // ---- stages ---------------------------------------------------------------------------------------------

    /** A plain move between Discovery and Negotiation. Lost and re-opening have their own dialogs (they need a reason). */
    public function moveToStage(string $stage, DealService $service): void
    {
        $deal = $this->dealModel();
        Gate::authorize('changeStage', $deal);

        $to = DealStage::tryFrom($stage);
        abort_if($to === null || $to === DealStage::Lost || $deal->stage === DealStage::Lost, 404);

        $this->run(fn () => $service->changeStage($deal, $to, $this->actor()), "The deal is now in {$to->label()}.");
    }

    public function markLost(DealService $service): void
    {
        $deal = $this->dealModel();
        Gate::authorize('markLost', $deal);

        $this->run(
            fn () => $service->changeStage($deal, DealStage::Lost, $this->actor(), $this->comment, ctype_digit($this->lostReasonId) ? (int) $this->lostReasonId : null),
            'The deal is marked as lost, and its lead with it.',
        );
    }

    public function reopenLost(DealService $service): void
    {
        $deal = $this->dealModel();
        Gate::authorize('reopenLost', $deal);

        $to = DealStage::tryFrom($this->reopenTo);
        abort_if($to === null || ! in_array($to, [DealStage::Discovery, DealStage::Negotiation], true), 404);

        $this->run(fn () => $service->changeStage($deal, $to, $this->actor(), $this->comment), "The deal is open again, in {$to->label()}.");
    }

    // ---- where it stands ------------------------------------------------------------------------------------

    public function saveProgress(DealService $service): void
    {
        $deal = $this->dealModel();
        Gate::authorize('updateProgress', $deal);

        $this->run(fn () => $service->updateProgress($deal, ['deal' => $this->progress], $this->actor()), 'Saved.');
    }

    // ---- approval -------------------------------------------------------------------------------------------

    /** Take an approved deal back to draft to correct its customer or lines. */
    public function reopenApproval(ApprovalService $approvals): void
    {
        $deal = $this->dealModel();
        Gate::authorize('reopenApproval', $deal);

        $this->resetErrorBag();
        $this->validate(
            ['reason' => ['required', 'string', 'min:3', 'max:1000']],
            ['reason.required' => 'Please say why you are re-opening this deal.', 'reason.min' => 'Please say why you are re-opening this deal.'],
        );

        try {
            $approvals->reopen($deal, $this->actor(), $this->reason);
        } catch (ApprovalStateException $e) {
            $this->addError('reason', $e->getMessage());

            return;
        }

        session()->flash('status', 'The deal is open for editing again. A quotation cannot be made from it until it is approved again.');
        $this->redirectRoute('crm.deals.edit', $deal, navigate: false);
    }

    // ---- ownership ------------------------------------------------------------------------------------------

    public function reassign(DealService $service): void
    {
        $deal = $this->dealModel();
        Gate::authorize('reassign', $deal);

        if (! ctype_digit($this->reassignTo)) {
            $this->resetErrorBag();
            $this->addError('to_user_id', 'Choose who the deal goes to.');

            return;
        }

        $this->run(fn () => $service->reassign($deal, (int) $this->reassignTo, $this->reason, $this->actor()), 'The deal has been handed over, and its lead with it. The new owner has been told.');
    }

    // ---- dialogs --------------------------------------------------------------------------------------------

    public function openDialog(string $which): void
    {
        abort_unless(in_array($which, self::DIALOGS, true), 404);

        $deal = $this->dealModel();
        $this->closeDialog();

        match ($which) {
            'lost' => Gate::authorize('markLost', $deal),
            'reopen-lost' => Gate::authorize('reopenLost', $deal),
            'reopen-approval' => Gate::authorize('reopenApproval', $deal),
            'reassign' => Gate::authorize('reassign', $deal),
            default => Gate::authorize('updateProgress', $deal),
        };

        if ($which === 'progress') {
            $this->progress = [
                'expected_close_date' => $deal->expected_close_date->toDateString(), 'probability' => (string) $deal->probability, 'remarks' => (string) ($deal->remarks ?? ''),
            ];
        }

        $this->dialog = $which;
    }

    public function closeDialog(): void
    {
        $this->dialog = '';
        $this->progress = [];
        $this->reason = $this->comment = $this->lostReasonId = $this->reassignTo = '';
        $this->reopenTo = DealStage::Discovery->value;
        $this->resetErrorBag();
    }

    /**
     * Run a service call and show what it says: a rule that was broken above the page, a field that needs attention in the
     * open dialog. On success the page is loaded afresh — unless the deal is no longer one this person can open (it was handed
     * to someone else), in which case they go to the deals list.
     *
     * @param  callable(): mixed  $action
     */
    private function run(callable $action, string $done): void
    {
        $this->resetErrorBag();

        try {
            $action();
        } catch (FieldRuleException $e) {
            $this->addError('workflow', $e->getMessage());

            return;
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        unset($this->dealModel);
        $this->closeDialog();
        session()->flash('status', $done);

        $deal = Deal::query()->find($this->dealId);

        $deal !== null && Gate::allows('view', $deal)
            ? $this->redirectRoute('crm.deals.show', $this->dealId, navigate: false)
            : $this->redirectRoute('crm.deals.index', navigate: false);
    }

    public function render(SalesScope $scope, SalesTeamService $teams): View
    {
        $deal = $this->dealModel();
        $actor = $this->actor();
        $lead = $deal->lead;

        // who the deal can go to: the person's own reach, among the people who work in the deal's branch, bar its owner
        $targets = [];
        if ($this->dialog === 'reassign' && Gate::allows('reassign', $deal)) {
            $managed = $scope->managedUserIds($actor);
            $targets = $teams->staffInBranch((int) $deal->location_id);
            $targets = $managed === null ? $targets : array_intersect_key($targets, array_flip([...$managed, (int) $actor->getKey()]));
            unset($targets[(int) $deal->assigned_to]);
        }

        $followups = $lead === null ? collect() : Followup::query()
            ->where('subject_type', $lead->getMorphClass())->where('subject_id', $lead->getKey())
            ->with(['assignee:id,name', 'outcome:id,name'])->orderByDesc('scheduled_at')->limit(15)->get();

        return view('crm.deals.show', [
            'deal' => $deal,
            'dealForecast' => app(DealForecaster::class)->forecast($deal),
            'lead' => $lead,
            'canViewLead' => $lead !== null && Gate::allows('view', $lead),
            'canViewCustomer' => $deal->customer !== null && Gate::allows('view', $deal->customer),
            'stages' => $deal->stageHistory()->with(['actor:id,name', 'lostReason:id,name'])->limit(50)->get(),
            'followups' => $followups,
            'history' => Gate::allows('viewAudit', $deal)
                ? ActivityLog::query()->with('causer')->where('subject_type', $deal->getMorphClass())->where('subject_id', $deal->id)->latest('id')->limit(30)->get()
                : collect(),
            'quotations' => app(QuotationAccess::class)->restrict(Quotation::query()->where('deal_id', $deal->getKey()), $actor)->orderByDesc('id')->limit(30)->get(),
            'orders' => app(SalesOrderAccess::class)->restrict(SalesOrder::query()->where('deal_id', $deal->getKey()), $actor)->orderByDesc('id')->limit(10)->get(),
            'canOrder' => $deal->isReadyForOrder() && Gate::allows('create', SalesOrder::class) && Gate::allows('work', $deal) && app(SalesOrderService::class)->liveOrderFor($deal) === null,
            'canQuote' => $deal->isApproved() && $deal->isOpen() && Gate::allows('create', Quotation::class) && Gate::allows('work', $deal),
            'canEdit' => Gate::allows('update', $deal),
            'canProgress' => Gate::allows('updateProgress', $deal),
            'canStage' => Gate::allows('changeStage', $deal),
            'canLose' => Gate::allows('markLost', $deal),
            'canReopenLost' => Gate::allows('reopenLost', $deal),
            'canReopenApproval' => Gate::allows('reopenApproval', $deal),
            'canReassign' => Gate::allows('reassign', $deal),
            'moves' => $deal->stage->isOpen() ? array_values(array_filter($deal->stage->manualMoves(), fn (DealStage $s) => $s !== DealStage::Lost)) : [],
            'reopenStages' => [DealStage::Discovery, DealStage::Negotiation],
            'lostReasons' => $this->dialog === 'lost' ? LostReason::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all() : [],
            'reassignTargets' => $targets,
        ]);
    }
}
