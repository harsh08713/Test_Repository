<?php

namespace App\Modules\Sales\Http\Livewire\Orders;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Services\CreditCheck;
use App\Modules\Sales\Services\InvoiceGenerator;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Shared\Exceptions\ApprovalStateException;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Services\ApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * One sales order: what was ordered, by whom and for when, the prices and tax, the credit check, where it stands and what has been done — with
 * the controls to send it for approval (the shared approval panel), allow it over the credit limit, make its invoice again if that failed,
 * re-open it, or cancel it.
 *
 * Every action authorises against the order (SalesOrderPolicy) and re-reads it through the tenant scopes first; the rules themselves live in
 * SalesOrderService and InvoiceGenerator.
 */
#[Title('Sales order')]
class SalesOrderShow extends Component
{
    use ScopesToPeople;

    private const DIALOGS = ['credit', 'cancel', 'reopen-approval'];

    #[Locked]
    public int $orderId = 0;

    /** Which dialog is open: '' or one of DIALOGS. */
    public string $dialog = '';

    public string $reason = '';

    public function mount(SalesOrder $order): void
    {
        Gate::authorize('view', $order);

        $this->orderId = $order->id;
    }

    #[Computed]
    public function orderModel(): SalesOrder
    {
        $order = SalesOrder::query()
            // the whole quotation, deal and lead, not column lists: the policies that decide whether they can be opened read their owner, creator and approval
            ->with(['quotation', 'deal', 'lead', 'customer', 'owner:id,name', 'location:id,name,code', 'placeOfSupply:id,name,gst_code', 'priceList:id,name', 'invoice', 'items.item:id,code,name', 'items.unit:id,code,name'])
            ->findOrFail($this->orderId);
        Gate::authorize('view', $order);

        return $order;
    }

    #[On('approval-changed')]
    public function refreshAfterApproval(): void
    {
        unset($this->orderModel);
    }

    // ---- credit ---------------------------------------------------------------------------------------------

    public function allowOverCredit(SalesOrderService $service): void
    {
        $order = $this->orderModel();
        Gate::authorize('overrideCredit', $order);

        $this->run(fn () => $service->overrideCredit($order, $this->reason, $this->actor()), 'The order may now go over the credit limit. Send it for approval.');
    }

    // ---- invoice ----------------------------------------------------------------------------------------------

    /** Make the invoice of an approved order that has none yet. Asking twice is harmless: there is one invoice per order. */
    public function makeInvoiceNow(InvoiceGenerator $generator): void
    {
        $order = $this->orderModel();
        Gate::authorize('makeInvoice', $order);

        $this->run(function () use ($generator, $order) {
            try {
                $generator->generateFor($order);
            } catch (Throwable) {
                throw new FieldRuleException('The invoice could not be made just now; the reason is shown above and it will be tried again automatically.');
            }
        }, 'The invoice has been made.');
    }

    // ---- cancel / approval ------------------------------------------------------------------------------------

    public function cancelOrder(SalesOrderService $service): void
    {
        $order = $this->orderModel();
        Gate::authorize('cancel', $order);

        $this->run(fn () => $service->cancel($order, $this->reason, $this->actor()), 'The order is cancelled'.($order->invoice !== null ? ', and its invoice with it.' : '.'));
    }

    /** Take an approved order back to editing — only while no invoice has been made from it. */
    public function reopenApproval(ApprovalService $approvals): void
    {
        $order = $this->orderModel();
        Gate::authorize('reopenApproval', $order);

        $this->resetErrorBag();
        $this->validate(
            ['reason' => ['required', 'string', 'min:3', 'max:1000']],
            ['reason.required' => 'Please say why you are re-opening this order.', 'reason.min' => 'Please say why you are re-opening this order.'],
        );

        try {
            $approvals->reopen($order, $this->actor(), $this->reason);
        } catch (ApprovalStateException $e) {
            $this->addError('reason', $e->getMessage());

            return;
        }

        session()->flash('status', 'The order is open for editing again. It has to be approved again.');
        $this->redirectRoute('sales.orders.edit', $order, navigate: false);
    }

    // ---- dialogs ----------------------------------------------------------------------------------------------

    public function openDialog(string $which): void
    {
        abort_unless(in_array($which, self::DIALOGS, true), 404);

        $order = $this->orderModel();
        $this->closeDialog();

        match ($which) {
            'credit' => Gate::authorize('overrideCredit', $order),
            'cancel' => Gate::authorize('cancel', $order),
            default => Gate::authorize('reopenApproval', $order),
        };

        $this->dialog = $which;
    }

    public function closeDialog(): void
    {
        $this->dialog = '';
        $this->reason = '';
        $this->resetErrorBag();
    }

    /**
     * Run a service call and show what it says: a rule that was broken above the page, a field that needs attention in the open dialog. On success
     * the page is loaded afresh.
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
        } catch (AuthorizationException $e) {
            $this->addError('workflow', $e->getMessage());

            return;
        }

        unset($this->orderModel);
        $this->closeDialog();
        session()->flash('status', $done);

        $this->redirectRoute('sales.orders.show', $this->orderId, navigate: false);
    }

    public function render(): View
    {
        $order = $this->orderModel();

        return view('sales.orders.show', [
            'order' => $order,
            'quotation' => $order->quotation,
            'deal' => $order->deal,
            'lead' => $order->lead,
            'canViewQuotation' => $order->quotation !== null && Gate::allows('view', $order->quotation),
            'canViewDeal' => $order->deal !== null && Gate::allows('view', $order->deal),
            'canViewCustomer' => Gate::allows('view', $order->customer),
            'canViewInvoice' => $order->invoice !== null && Gate::allows('view', $order->invoice),
            'states' => $order->stateHistory()->with('actor:id,name')->limit(50)->get(),
            'history' => Gate::allows('viewAudit', $order)
                ? ActivityLog::query()->with('causer')->where('subject_type', $order->getMorphClass())->where('subject_id', $order->id)->latest('id')->limit(30)->get()
                : collect(),
            'canEdit' => Gate::allows('update', $order),
            'canCredit' => Gate::allows('overrideCredit', $order),
            'canCancel' => Gate::allows('cancel', $order),
            'canReopen' => Gate::allows('reopenApproval', $order),
            'canMakeInvoice' => Gate::allows('makeInvoice', $order) && $order->invoice === null,
            'canDownload' => Gate::allows('download', $order),
            'creditText' => $order->credit_limit === null ? null : app(CreditCheck::class)->describe($order),
        ]);
    }
}
