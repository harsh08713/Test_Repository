<?php

namespace App\Modules\Sales\Http\Livewire\Invoices;

use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** One sales invoice, read only: what was invoiced, to whom, at which prices and tax, with the order it came from. */
#[Title('Sales invoice')]
class SalesInvoiceShow extends Component
{
    use ScopesToPeople;

    #[Locked]
    public int $invoiceId = 0;

    public function mount(SalesInvoice $invoice): void
    {
        Gate::authorize('view', $invoice);

        $this->invoiceId = $invoice->id;
    }

    public function render(): View
    {
        $invoice = SalesInvoice::query()
            ->with(['order', 'customer', 'owner:id,name', 'location:id,name,code', 'placeOfSupply:id,name,gst_code', 'priceList:id,name', 'items.unit:id,code,name'])
            ->findOrFail($this->invoiceId);
        Gate::authorize('view', $invoice);

        return view('sales.invoices.show', [
            'invoice' => $invoice,
            'canViewOrder' => $invoice->order !== null && Gate::allows('view', $invoice->order),
            'canViewCustomer' => Gate::allows('view', $invoice->customer),
            'canDownload' => Gate::allows('download', $invoice),
            'canReturn' => Gate::allows('makeFor', [SalesReturn::class, $invoice]),
            // only the returns this person may open; a count shown to someone who cannot open them would give them away
            'returns' => SalesReturn::query()->where('sales_invoice_id', $invoice->id)->orderByDesc('id')->get()->filter(fn (SalesReturn $r) => Gate::allows('view', $r)),
            'history' => Gate::allows('viewAudit', $invoice)
                ? ActivityLog::query()->with('causer')->where('subject_type', $invoice->getMorphClass())->where('subject_id', $invoice->id)->latest('id')->limit(30)->get()
                : collect(),
        ]);
    }
}
