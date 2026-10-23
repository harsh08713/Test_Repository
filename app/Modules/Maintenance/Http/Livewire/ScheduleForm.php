<?php

namespace App\Modules\Maintenance\Http\Livewire;

use App\Modules\Maintenance\Models\Asset;
use App\Modules\Maintenance\Models\MaintenancePlan;
use App\Modules\Maintenance\Services\MaintenancePlanService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Make or change a preventive schedule: the asset, the job, how often, how many days ahead its work order is made, and a checklist (one task per line). */
#[Title('Preventive schedule')]
class ScheduleForm extends Component
{
    #[Locked]
    public ?int $planId = null;

    /** @var array<string, string> */
    public array $f = ['code' => '', 'asset_id' => '', 'name' => '', 'checklist' => '', 'every_n' => '1', 'unit' => 'months', 'lead_days' => '3', 'estimated_hours' => '', 'next_due_on' => '', 'remarks' => ''];

    public function mount(?int $editing = null): void
    {
        if ($editing !== null) {
            $plan = MaintenancePlan::query()->findOrFail($editing);
            Gate::authorize('update', $plan);
            $this->planId = $plan->id;
            $this->f = [
                'code' => $plan->code, 'asset_id' => (string) $plan->asset_id, 'name' => $plan->name, 'checklist' => (string) $plan->checklist, 'every_n' => (string) $plan->every_n, 'unit' => $plan->unit,
                'lead_days' => (string) $plan->lead_days, 'estimated_hours' => $plan->estimated_hours === null ? '' : rtrim(rtrim((string) $plan->estimated_hours, '0'), '.'), 'next_due_on' => $plan->next_due_on->toDateString(), 'remarks' => (string) $plan->remarks,
            ];

            return;
        }

        Gate::authorize('create', MaintenancePlan::class);
        $this->f['next_due_on'] = now()->addMonth()->toDateString();
    }

    public function save(MaintenancePlanService $service): void
    {
        $this->resetErrorBag();

        try {
            if ($this->planId !== null) {
                $plan = MaintenancePlan::query()->findOrFail($this->planId);
                Gate::authorize('update', $plan);
                $service->update($plan, $this->f, Auth::user());
            } else {
                Gate::authorize('create', MaintenancePlan::class);
                $service->create($this->f, Auth::user());
            }
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        session()->flash('status', 'Schedule saved.');
        $this->redirectRoute('maintenance.schedules.index', navigate: false);
    }

    public function render(): View
    {
        return view('maintenance.schedule-form', [
            'assets' => Asset::query()->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name'])->mapWithKeys(fn ($a) => [$a->id => $a->code.' · '.$a->name])->all(),
            'units' => MaintenancePlan::UNITS, 'editing' => $this->planId !== null,
        ]);
    }
}
