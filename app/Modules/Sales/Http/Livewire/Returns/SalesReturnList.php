<?php

namespace App\Modules\Sales\Http\Livewire\Returns;

use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\SalesOrderAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** The returns and credit notes a person may see — those against invoices credited to them, their team's, or everyone's (Accounts, Operations). */
#[Title('Returns and credit notes')]
class SalesReturnList extends Component
{
    use ScopesToPeople, WithPagination;

    public const PER_PAGE = 20;

    #[Url(as: 'q')]
    public string $search = '';

    /** '' (all), 'draft', 'pending', 'approved' or 'rejected'. */
    #[Url(as: 'approval')]
    public string $approval = '';

    #[Url(as: 'type')]
    public string $type = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', SalesReturn::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'approval', 'type'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $like = '%'.addcslashes(trim($this->search), '%_\\').'%';

        $returns = app(SalesOrderAccess::class)->restrictReturns(SalesReturn::query(), $this->actor())
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('sales_returns.party_name', 'like', $like)->orWhere('sales_returns.return_number', 'like', $like)))
            ->when(in_array($this->approval, ['draft', 'pending', 'approved', 'rejected'], true), fn (Builder $q) => $q->where('sales_returns.approval_status', $this->approval))
            ->when(in_array($this->type, [SalesReturn::RETURN, SalesReturn::CREDIT_NOTE], true), fn (Builder $q) => $q->where('sales_returns.return_type', $this->type))
            ->with(['invoice:id,invoice_number', 'location:id,name,code'])->orderByDesc('sales_returns.id')->paginate(self::PER_PAGE);

        return view('sales.returns.list', ['returns' => $returns, 'canMake' => Gate::allows('create', SalesReturn::class)]);
    }
}
