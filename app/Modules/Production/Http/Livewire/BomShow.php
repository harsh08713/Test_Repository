<?php

namespace App\Modules\Production\Http\Livewire;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Services\BomService;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/** One bill of materials: its components, what it takes down to raw materials, the approval panel, and making it inactive (or active again). */
#[Title('Bill of materials')]
class BomShow extends Component
{
    #[Locked]
    public int $bomId = 0;

    public function mount(Bom $bom): void
    {
        Gate::authorize('view', $bom);
        $this->bomId = $bom->id;
    }

    #[Computed]
    public function bomModel(): Bom
    {
        $bom = Bom::query()->with(['item', 'components.item:id,code,name,base_unit_id', 'components.item.baseUnit:id,code'])->findOrFail($this->bomId);
        Gate::authorize('view', $bom);

        return $bom;
    }

    #[On('approval-changed')]
    public function refreshAfterApproval(): void
    {
        unset($this->bomModel);
    }

    public function toggle(BomService $service): void
    {
        $bom = $this->bomModel();
        Gate::authorize('toggle', $bom);
        $this->resetErrorBag();

        try {
            $service->setStatus($bom, $bom->status !== 'active', Auth::user());
        } catch (FieldRuleException $e) {
            $this->addError('workflow', $e->getMessage());

            return;
        }

        unset($this->bomModel);
        session()->flash('status', 'Saved.');
    }

    public function render(BomService $service): View
    {
        $bom = $this->bomModel();

        return view('production.bom-show', [
            'bom' => $bom, 'needs' => $service->explode($bom),
            'canEdit' => Gate::allows('update', $bom), 'canToggle' => Gate::allows('toggle', $bom) && $bom->isApproved(),
            'canOrder' => Gate::allows('create', ProductionOrder::class) && $bom->isUsable(),
            'history' => Gate::allows('viewAudit', $bom)
                ? ActivityLog::query()->with('causer')->where('subject_type', $bom->getMorphClass())->where('subject_id', $bom->id)->latest('id')->limit(30)->get()
                : collect(),
        ]);
    }
}
