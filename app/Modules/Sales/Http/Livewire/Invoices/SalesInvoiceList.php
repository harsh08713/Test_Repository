<?php

namespace App\Modules\Sales\Http\Livewire\Invoices;

use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesOrderAccess;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** The sales invoices a person may see — those of the orders credited to them, their team's, or everyone's (Accounts, Operations). Invoices are made by the system when an order is approved. */
#[Title('Sales invoices')]
class SalesInvoiceList extends Component
{
    use ScopesToPeople, WithPagination;

    public const PER_PAGE = 20;

    #[Url(as: 'q')]
    public string $search = '';

    /** '' (all), 'issued' or 'cancelled'. */
    #[Url(as: 'status')]
    public string $status = 'issued';

    #[Url(as: 'branch')]
    public string $branch = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', SalesInvoice::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'status', 'branch'], true)) {
            $this->resetPage();
        }
    }

    public function setStatus(string $status): void
    {
        $this->status = in_array($status, ['issued', 'cancelled', ''], true) ? $status : 'issued';
        $this->resetPage();
    }

    /** @return Builder<SalesInvoice> */
    private function base(): Builder
    {
        $like = '%'.addcslashes(trim($this->search), '%_\\').'%';

        return app(SalesOrderAccess::class)->restrictInvoices(SalesInvoice::query(), $this->actor())
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('sales_invoices.party_name', 'like', $like)->orWhere('sales_invoices.invoice_number', 'like', $like)->orWhere('sales_invoices.customer_po_number', 'like', $like)))
            ->when(ctype_digit($this->branch), fn (Builder $q) => $q->where('sales_invoices.location_id', (int) $this->branch));
    }

    public function render(): View
    {
        $invoices = $this->base()->when($this->status !== '', fn (Builder $q) => $q->where('sales_invoices.status', $this->status))
            ->with(['location:id,name,code', 'order:id,so_number'])->orderByDesc('sales_invoices.id')->paginate(self::PER_PAGE);

        /** @var array<string, int> $counts */
        $counts = $this->base()->toBase()->selectRaw('sales_invoices.status as status, count(*) as total')->groupBy('sales_invoices.status')->pluck('total', 'status')->map(fn ($n) => (int) $n)->all();
        $issued = $this->base()->where('sales_invoices.status', SalesInvoice::ISSUED)->toBase()->selectRaw('coalesce(sum(sales_invoices.grand_total), 0) as value')->value('value');

        return view('sales.invoices.list', [
            'invoices' => $invoices,
            'tabs' => [['issued', 'Issued', $counts['issued'] ?? 0], ['cancelled', 'Cancelled', $counts['cancelled'] ?? 0], ['', 'All', array_sum($counts)]],
            'issuedValue' => (string) $issued,
            'branches' => Location::query()->orderBy('name')->pluck('name', 'id')->all(),
            'today' => now()->startOfDay(),
        ]);
    }
}
