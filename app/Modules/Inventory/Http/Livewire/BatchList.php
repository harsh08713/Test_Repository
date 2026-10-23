<?php

namespace App\Modules\Inventory\Http\Livewire;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Services\StockQuery;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Batches with stock, soonest expiry first, and the value that is about to expire (FORMULAS.md `expiry_exposure`). */
#[Title('Batches and expiry')]
class BatchList extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    #[Url(as: 'q')]
    public string $search = '';

    /** '' (with stock), 'expiring' (within the warning window), 'expired', 'all' */
    #[Url(as: 'show')]
    public string $show = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Batch::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'show'], true)) {
            $this->resetPage();
        }
    }

    public function render(SettingsService $settings, StockQuery $stock): View
    {
        $days = (int) $settings->get('inventory.expiry_warning_days');
        $days = $days > 0 ? $days : 30;   // the warning may be off, but the page still needs a window to show
        $like = '%'.addcslashes(trim($this->search), '%_\\').'%';

        $batches = Batch::query()->with(['item:id,code,name,base_unit_id', 'item.baseUnit:id,code'])->withSum('balances as on_hand', 'quantity')->withSum('balances as stock_value', 'value')
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('batch_no', 'like', $like)->orWhere('lot_no', 'like', $like)
                ->orWhereHas('item', fn ($i) => $i->where('name', 'like', $like)->orWhere('code', 'like', $like))))
            ->when($this->show !== 'all', fn (Builder $q) => $q->whereHas('balances', fn ($b) => $b->where('quantity', '>', 0)))
            ->when($this->show === 'expiring', fn (Builder $q) => $q->whereBetween('expiry_date', [now()->toDateString(), now()->addDays($days)->toDateString()]))
            ->when($this->show === 'expired', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('status', 'expired')->orWhere('expiry_date', '<', now()->toDateString())))
            ->orderByRaw('expiry_date is null')->orderBy('expiry_date')->orderBy('id')->paginate(self::PER_PAGE);

        return view('inventory.batches', [
            'batches' => $batches,
            'days' => $days,
            'exposure' => $stock->expiryExposure($days),
            'canEnter' => Gate::allows('create', Batch::class),
            'canReportLoss' => Gate::allows('create', LossIncident::class),
        ]);
    }
}
