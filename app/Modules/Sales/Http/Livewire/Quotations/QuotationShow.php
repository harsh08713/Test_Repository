<?php

namespace App\Modules\Sales\Http\Livewire\Quotations;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\QuotationConversionGuard;
use App\Modules\Sales\Services\QuotationDelivery;
use App\Modules\Sales\Services\QuotationService;
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
 * One revision of a quotation: what it says, where it stands with the customer and inside the company, and the controls to send it
 * for approval (the shared approval panel), e-mail it or record that it was handed over, record the customer's answer, make a new
 * revision, withdraw it, or re-open an approved draft.
 *
 * Every action authorises against the quotation (QuotationPolicy) and re-reads it through the tenant scopes first; the rules
 * themselves live in QuotationService.
 */
#[Title('Quotation')]
class QuotationShow extends Component
{
    use ScopesToPeople;

    private const DIALOGS = ['send', 'accept', 'decline', 'revise', 'cancel', 'reopen-approval'];

    #[Locked]
    public int $quotationId = 0;

    /** Which dialog is open: '' or one of DIALOGS. */
    public string $dialog = '';

    public string $channel = 'email';

    public string $to = '';

    public string $cc = '';

    public string $subject = '';

    public string $message = '';

    public string $note = '';

    public string $reason = '';

    public function mount(Quotation $quotation): void
    {
        Gate::authorize('view', $quotation);

        $this->quotationId = $quotation->id;
    }

    #[Computed]
    public function quotationModel(): Quotation
    {
        $quotation = Quotation::query()
            // the whole lead and deal, not column lists: the policies that decide whether they can be opened read their owner, creator and approval
            ->with(['lead', 'deal', 'customer', 'location:id,name,code', 'placeOfSupply:id,name,gst_code', 'priceList:id,name', 'previous:id,quotation_number,revision_no',
                'items.item:id,code,name', 'items.unit:id,code,name'])
            ->findOrFail($this->quotationId);
        Gate::authorize('view', $quotation);

        return $quotation;
    }

    #[On('approval-changed')]
    public function refreshAfterApproval(): void
    {
        unset($this->quotationModel);
    }

    // ---- sending --------------------------------------------------------------------------------------------

    public function sendQuotation(QuotationService $service): void
    {
        $quotation = $this->quotationModel();
        Gate::authorize('send', $quotation);

        $this->run(fn () => $service->send($quotation, [
            'channel' => $this->channel, 'to' => $this->to, 'cc' => $this->cc, 'subject' => $this->subject, 'message' => $this->message,
        ], $this->actor()), $this->channel === 'email' ? 'The quotation has been e-mailed to the customer.' : 'The quotation is recorded as sent.');
    }

    public function recordAccepted(QuotationService $service): void
    {
        $quotation = $this->quotationModel();
        Gate::authorize('respond', $quotation);

        $this->run(fn () => $service->accept($quotation, $this->note, $this->actor()), 'Recorded: the customer accepted the quotation.');
    }

    public function recordDeclined(QuotationService $service): void
    {
        $quotation = $this->quotationModel();
        Gate::authorize('respond', $quotation);

        $this->run(fn () => $service->decline($quotation, $this->reason, $this->actor()), 'Recorded: the customer declined the quotation.');
    }

    public function reviseQuotation(QuotationService $service): void
    {
        $quotation = $this->quotationModel();
        Gate::authorize('revise', $quotation);

        $this->resetErrorBag();

        try {
            $new = $service->revise($quotation, $this->actor(), $this->reason);
        } catch (FieldRuleException $e) {
            $this->addError('workflow', $e->getMessage());

            return;
        }

        session()->flash('status', "Revision {$new->revision_no} is ready as a draft. Change what needs changing, then send it for approval.");
        $this->redirectRoute('sales.quotations.edit', $new, navigate: false);
    }

    public function cancelQuotation(QuotationService $service): void
    {
        $quotation = $this->quotationModel();
        Gate::authorize('cancel', $quotation);

        $this->run(fn () => $service->cancel($quotation, $this->reason, $this->actor()), 'The quotation is withdrawn.');
    }

    // ---- approval -------------------------------------------------------------------------------------------

    /** Take an approved draft back to editing (it needs approving again). */
    public function reopenApproval(ApprovalService $approvals): void
    {
        $quotation = $this->quotationModel();
        Gate::authorize('reopenApproval', $quotation);

        $this->resetErrorBag();
        $this->validate(
            ['reason' => ['required', 'string', 'min:3', 'max:1000']],
            ['reason.required' => 'Please say why you are re-opening this quotation.', 'reason.min' => 'Please say why you are re-opening this quotation.'],
        );

        try {
            $approvals->reopen($quotation, $this->actor(), $this->reason);
        } catch (ApprovalStateException $e) {
            $this->addError('reason', $e->getMessage());

            return;
        }

        session()->flash('status', 'The quotation is open for editing again. It has to be approved again before it can be sent.');
        $this->redirectRoute('sales.quotations.edit', $quotation, navigate: false);
    }

    // ---- dialogs --------------------------------------------------------------------------------------------

    public function openDialog(string $which): void
    {
        abort_unless(in_array($which, self::DIALOGS, true), 404);

        $quotation = $this->quotationModel();
        $this->closeDialog();

        match ($which) {
            'send' => Gate::authorize('send', $quotation),
            'accept', 'decline' => Gate::authorize('respond', $quotation),
            'revise' => Gate::authorize('revise', $quotation),
            'cancel' => Gate::authorize('cancel', $quotation),
            default => Gate::authorize('reopenApproval', $quotation),
        };

        if ($which === 'send') {
            $this->to = (string) $quotation->email;
            $this->subject = app(QuotationDelivery::class)->defaultSubject($quotation);
        }

        $this->dialog = $which;
    }

    public function closeDialog(): void
    {
        $this->dialog = '';
        $this->channel = 'email';
        $this->to = $this->cc = $this->subject = $this->message = $this->note = $this->reason = '';
        $this->resetErrorBag();
    }

    /**
     * Run a service call and show what it says: a rule that was broken above the page, a field that needs attention in the open
     * dialog. On success the page is loaded afresh.
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

        unset($this->quotationModel);
        $this->closeDialog();
        session()->flash('status', $done);

        $this->redirectRoute('sales.quotations.show', $this->quotationId, navigate: false);
    }

    public function render(): View
    {
        $quotation = $this->quotationModel();
        $guard = app(QuotationConversionGuard::class)->blocks($quotation);

        return view('sales.quotations.show', [
            'quotation' => $quotation,
            'lead' => $quotation->lead,
            'deal' => $quotation->deal,
            'canViewLead' => $quotation->lead !== null && Gate::allows('view', $quotation->lead),
            'canViewDeal' => $quotation->deal !== null && Gate::allows('view', $quotation->deal),
            'canViewCustomer' => $quotation->customer !== null && Gate::allows('view', $quotation->customer),
            'revisions' => $quotation->revisions()->get(['id', 'quotation_number', 'revision_no', 'state', 'grand_total', 'quotation_date']),
            'states' => $quotation->stateHistory()->with('actor:id,name')->limit(50)->get(),
            'dispatches' => $quotation->dispatches()->with('actor:id,name')->limit(20)->get(),
            'history' => Gate::allows('viewAudit', $quotation)
                ? ActivityLog::query()->with('causer')->where('subject_type', $quotation->getMorphClass())->where('subject_id', $quotation->id)->latest('id')->limit(30)->get()
                : collect(),
            'canEdit' => Gate::allows('update', $quotation),
            'canSend' => Gate::allows('send', $quotation),
            'canRespond' => Gate::allows('respond', $quotation),
            'canRevise' => Gate::allows('revise', $quotation),
            'canCancel' => Gate::allows('cancel', $quotation),
            'canReopenApproval' => Gate::allows('reopenApproval', $quotation),
            'canDownload' => Gate::allows('download', $quotation),
            'orders' => app(SalesOrderAccess::class)->restrict(SalesOrder::query()->where('quotation_id', $quotation->getKey()), $this->actor())->orderByDesc('id')->get(),
            'canOrder' => $guard['hard'] === [] && ($guard['soft'] === [] || $this->actor()->can('quotation.convert_exception'))
                && Gate::allows('create', SalesOrder::class) && Gate::allows('work', $quotation) && app(SalesOrderService::class)->liveOrderFor($quotation) === null
                && ($quotation->deal === null || app(SalesOrderService::class)->liveOrderFor($quotation->deal) === null),
            'conversion' => $guard,
        ]);
    }
}
