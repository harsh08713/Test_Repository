<?php

namespace App\Modules\Quality\Http\Livewire;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Masters\Services\ItemLookup;
use App\Modules\Quality\Models\InspectionPlan;
use App\Modules\Quality\Services\InspectionPlanService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Make (or change a draft of) an inspection plan: the item, the stage, and the checks (a measurement between limits, or pass / fail, optionally critical). */
#[Title('Inspection plan')]
class PlanForm extends Component
{
    #[Locked]
    public ?int $planId = null;

    public string $code = '';

    public string $name = '';

    public string $stage = 'incoming';

    public string $itemId = '';

    public string $itemLabel = '';

    public string $itemSearch = '';

    public string $remarks = '';

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public function mount(?int $editing = null): void
    {
        if ($editing !== null) {
            $plan = InspectionPlan::query()->with(['parameters', 'item:id,code,name'])->findOrFail($editing);
            Gate::authorize('update', $plan);

            $this->planId = $plan->id;
            $this->code = $plan->code;
            $this->name = $plan->name;
            $this->stage = $plan->stage;
            $this->itemId = (string) $plan->item_id;
            $this->itemLabel = trim($plan->item?->code.' · '.$plan->item?->name);
            $this->remarks = (string) $plan->remarks;
            $trim = fn ($v) => $v === null ? '' : (rtrim(rtrim((string) $v, '0'), '.') ?: '0');
            $this->rows = $plan->parameters->map(fn ($p) => [
                'key' => Str::random(6), 'name' => $p->name, 'kind' => $p->kind, 'unit_text' => (string) $p->unit_text, 'min_value' => $trim($p->min_value), 'max_value' => $trim($p->max_value),
                'is_critical' => (bool) $p->is_critical, 'method' => (string) $p->method,
            ])->all();

            return;
        }

        Gate::authorize('create', InspectionPlan::class);
        $this->rows = [$this->blank()];
    }

    /** @return array<string, mixed> */
    private function blank(): array
    {
        return ['key' => Str::random(6), 'name' => '', 'kind' => 'measure', 'unit_text' => '', 'min_value' => '', 'max_value' => '', 'is_critical' => false, 'method' => ''];
    }

    public function addRow(): void
    {
        if (count($this->rows) < InspectionPlanService::MAX_LINES) {
            $this->rows[] = $this->blank();
        }
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows) ?: [$this->blank()];
    }

    public function pickItem(int $id): void
    {
        $item = app(ItemLookup::class)->usable($id);

        if ($item !== null) {
            $this->itemId = (string) $item->id;
            $this->itemLabel = trim($item->code.' · '.$item->name);
            $this->itemSearch = '';
        }
    }

    public function clearItem(): void
    {
        $this->itemId = $this->itemLabel = '';
    }

    public function save(InspectionPlanService $service): void
    {
        $this->resetErrorBag();
        $input = ['code' => $this->code, 'name' => $this->name, 'item_id' => $this->itemId, 'stage' => $this->stage, 'remarks' => $this->remarks, 'items' => $this->rows];

        try {
            if ($this->planId !== null) {
                $plan = InspectionPlan::query()->findOrFail($this->planId);
                Gate::authorize('update', $plan);
                $plan = $service->update($plan, $input, Auth::user());
            } else {
                Gate::authorize('create', InspectionPlan::class);
                $plan = $service->create($input, Auth::user());
            }
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        } catch (FieldRuleException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('status', "Plan {$plan->code} saved. Send it for approval when it is ready.");
        $this->redirectRoute('quality.plans.show', $plan, navigate: false);
    }

    public function render(): View
    {
        return view('quality.plan-form', [
            'itemResults' => trim($this->itemSearch) === '' ? collect() : app(ItemLookup::class)->search($this->itemSearch, 6),
            'stages' => InspectionPlan::STAGES, 'editing' => $this->planId !== null, 'max' => InspectionPlanService::MAX_LINES,
        ]);
    }
}
