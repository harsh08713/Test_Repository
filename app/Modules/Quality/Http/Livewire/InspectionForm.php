<?php

namespace App\Modules\Quality\Http\Livewire;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Procurement\Models\Grn;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionPlan;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Start an inspection: the approved plan, the branch, the size of the lot and (for an item tracked by batch) the batch. It can be started from a production order (`?order=`) or a goods
 * receipt (`?grn=`), which fills in the branch, the source and — for an order — the item and the quantity made.
 */
#[Title('Start an inspection')]
class InspectionForm extends Component
{
    #[Url(as: 'plan')]
    public string $planId = '';

    public string $locationId = '';

    public string $lotQuantity = '';

    public string $batchId = '';

    #[Locked]
    public string $sourceType = 'manual';

    #[Locked]
    public string $sourceId = '';

    public string $sourceLabel = '';

    #[Locked]
    public string $onlyItem = '';

    public function mount(): void
    {
        Gate::authorize('create', Inspection::class);

        $order = (string) request()->query('order', '');
        $grn = (string) request()->query('grn', '');

        if (ctype_digit($order) && ($o = ProductionOrder::query()->find((int) $order)) !== null) {
            $this->sourceType = 'production_order';
            $this->sourceId = (string) $o->id;
            $this->sourceLabel = $o->order_number;
            $this->locationId = (string) $o->location_id;
            $this->onlyItem = (string) $o->item_id;
            $good = bcadd((string) DB::table('production_outputs')->where('production_order_id', $o->id)->sum('good_quantity'), '0', 3);
            $this->lotQuantity = bccomp($good, '0', 3) > 0 ? rtrim(rtrim($good, '0'), '.') : '';
        } elseif (ctype_digit($grn) && ($g = Grn::query()->find((int) $grn)) !== null) {
            $this->sourceType = 'grn';
            $this->sourceId = (string) $g->id;
            $this->sourceLabel = $g->grn_number;
            $this->locationId = (string) $g->location_id;
        }

        if ($this->planId !== '' && ! (ctype_digit($this->planId) && InspectionPlan::query()->usable()->whereKey((int) $this->planId)->exists())) {
            $this->planId = '';
        }
    }

    public function save(InspectionService $service): void
    {
        $this->resetErrorBag();

        try {
            Gate::authorize('create', Inspection::class);
            $inspection = $service->start([
                'inspection_plan_id' => $this->planId, 'location_id' => $this->locationId, 'lot_quantity' => $this->lotQuantity, 'batch_id' => $this->batchId,
                'source_type' => $this->sourceType, 'source_id' => $this->sourceId, 'source_label' => $this->sourceLabel,
            ], Auth::user());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        } catch (FieldRuleException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('status', "Inspection {$inspection->inspection_number} started.".($inspection->held_batch ? ' The batch is on hold until it is decided.' : ''));
        $this->redirectRoute('quality.inspections.show', $inspection, navigate: false);
    }

    public function render(): View
    {
        $plans = InspectionPlan::query()->usable()->with('item:id,name,tracking')->when($this->onlyItem !== '', fn ($q) => $q->where('item_id', (int) $this->onlyItem))->orderBy('code')->get();
        $plan = ctype_digit($this->planId) ? $plans->firstWhere('id', (int) $this->planId) : null;
        $batches = $plan?->item?->tracking === 'batch'
            ? Batch::query()->where('item_id', $plan->item_id)->orderByDesc('id')->limit(100)->get()->mapWithKeys(fn (Batch $b) => [$b->id => $b->batch_no.($b->expiry_date ? ' · exp '.$b->expiry_date->format('d-m-Y') : '').($b->status !== 'active' ? ' · '.$b->status : '')])->all()
            : [];

        return view('quality.inspection-form', [
            'plans' => $plans->mapWithKeys(fn (InspectionPlan $p) => [$p->id => $p->code.' · '.$p->name.' · '.$p->item?->name.' · '.(InspectionPlan::STAGES[$p->stage] ?? $p->stage)])->all(),
            'branches' => Location::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(), 'batches' => $batches,
        ]);
    }
}
