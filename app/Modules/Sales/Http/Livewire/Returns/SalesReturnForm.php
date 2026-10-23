<?php

namespace App\Modules\Sales\Http\Livewire\Returns;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesReturnService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Make (or change a draft of) a return or credit note against one invoice: the type, a reason, and how much of each invoice line comes back.
 * The figures shown are SalesReturnService::preview — the same arithmetic the save uses; nothing is trusted from the browser but the typed quantities.
 */
#[Title('Return or credit note')]
class SalesReturnForm extends Component
{
    use ScopesToPeople;

    #[Locked]
    public int $invoiceId = 0;

    #[Locked]
    public ?int $returnId = null;

    public string $returnType = SalesReturn::RETURN;

    public string $returnDate = '';

    public string $reason = '';

    /** @var array<int|string, string> invoice line id => quantity to return, as typed */
    public array $quantities = [];

    public function mount(?int $invoice = null, ?int $editing = null): void
    {
        $this->returnDate = now()->toDateString();

        if ($editing !== null) {
            $return = SalesReturn::query()->with('items')->findOrFail($editing);
            Gate::authorize('update', $return);

            $this->returnId = $return->id;
            $this->invoiceId = $return->sales_invoice_id;
            $this->returnType = $return->return_type;
            $this->returnDate = $return->return_date->toDateString();
            $this->reason = $return->reason;
            $this->quantities = $return->items->mapWithKeys(fn ($l) => [$l->sales_invoice_item_id => rtrim(rtrim((string) $l->quantity, '0'), '.')])->all();

            return;
        }

        $invoice = SalesInvoice::query()->findOrFail((int) $invoice);
        Gate::authorize('makeFor', [SalesReturn::class, $invoice]);
        $this->invoiceId = $invoice->id;
    }

    public function save(SalesReturnService $service): void
    {
        $this->resetErrorBag();
        $invoice = SalesInvoice::query()->findOrFail($this->invoiceId);
        $input = ['return_type' => $this->returnType, 'return_date' => $this->returnDate, 'reason' => $this->reason, 'lines' => $this->quantities];

        try {
            if ($this->returnId !== null) {
                $return = SalesReturn::query()->findOrFail($this->returnId);
                Gate::authorize('update', $return);
                $return = $service->update($return, $input, $this->actor());
            } else {
                Gate::authorize('makeFor', [SalesReturn::class, $invoice]);
                $return = $service->create($invoice, $input, $this->actor());
            }
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        } catch (FieldRuleException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('status', $this->returnId !== null ? 'Saved.' : "{$return->typeLabel()} {$return->return_number} saved as a draft. Send it for approval when it is ready.");
        $this->redirectRoute('sales.returns.show', $return, navigate: false);
    }

    public function render(SalesReturnService $service): View
    {
        $invoice = SalesInvoice::query()->with('order:id,so_number')->findOrFail($this->invoiceId);
        $preview = $service->preview($invoice, $this->quantities, $this->returnId);

        return view('sales.returns.form', [
            'invoice' => $invoice,
            'lines' => SalesInvoiceItem::query()->with('unit:id,code,name')->where('sales_invoice_id', $invoice->id)->orderBy('line_no')->get(),
            'left' => $service->returnable($invoice, $this->returnId),
            'preview' => $preview,
            'editing' => $this->returnId !== null,
            'today' => now()->toDateString(),
        ]);
    }
}
