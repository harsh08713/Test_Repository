<?php

namespace App\Modules\Masters\Http\Livewire\Ledgers;

use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Models\Ledger;
use App\Modules\Shared\Enums\ApprovalStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** The ledgers of the active company, filterable by type, approval state and status. */
#[Title('Ledgers')]
class LedgerList extends Component
{
    use WithPagination;

    #[Url(as: 'type')]
    public string $type = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'approval')]
    public string $approval = '';

    #[Url(as: 'status')]
    public string $status = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Ledger::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['type', 'search', 'approval', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function toggleStatus(int $id): void
    {
        $ledger = Ledger::query()->findOrFail($id);
        Gate::authorize('changeStatus', $ledger);

        // saveQuietly is not used: the change is audited like any other edit.
        $ledger->forceFill(['status' => $ledger->status === 'active' ? 'inactive' : 'active'])->save();
    }

    public function render(): View
    {
        $type = LedgerType::tryFrom($this->type);

        $ledgers = Ledger::query()
            ->with('accountGroup:id,name')
            ->when($type !== null, fn ($q) => $q->ofType($type))
            ->when($this->approval !== '' && ApprovalStatus::tryFrom($this->approval) !== null, fn ($q) => $q->where('approval_status', $this->approval))
            ->when(in_array($this->status, ['active', 'inactive'], true), fn ($q) => $q->where('status', $this->status))
            ->when(trim($this->search) !== '', function ($q) {
                $like = '%'.addcslashes(trim($this->search), '%_\\').'%';
                $q->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name', 'like', $like)
                    ->orWhere('legal_name', 'like', $like)->orWhere('gstin', 'like', $like));
            })
            ->orderBy('name')
            ->paginate(15);

        return view('masters.ledgers.list', [
            'ledgers' => $ledgers,
            'types' => LedgerType::cases(),
            'creatable' => array_values(array_filter(LedgerType::cases(), fn (LedgerType $t) => Gate::allows('createOfType', [Ledger::class, $t]))),
            'approvalStatuses' => ApprovalStatus::cases(),
        ]);
    }
}
