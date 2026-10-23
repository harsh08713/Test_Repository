<?php

namespace App\Modules\Quality\Http\Livewire;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionPlan;
use App\Modules\Quality\Services\InspectionPlanService;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/** One inspection plan: its checks and limits, the approval panel, making it inactive (or active again), and starting an inspection from it. */
#[Title('Inspection plan')]
class PlanShow extends Component
{
    #[Locked]
    public int $planId = 0;

    public function mount(InspectionPlan $plan): void
    {
        Gate::authorize('view', $plan);
        $this->planId = $plan->id;
    }

    #[Computed]
    public function planModel(): InspectionPlan
    {
        $plan = InspectionPlan::query()->with(['item', 'parameters'])->findOrFail($this->planId);
        Gate::authorize('view', $plan);

        return $plan;
    }

    #[On('approval-changed')]
    public function refreshAfterApproval(): void
    {
        unset($this->planModel);
    }

    public function toggle(InspectionPlanService $service): void
    {
        $plan = $this->planModel();
        Gate::authorize('toggle', $plan);
        $this->resetErrorBag();

        try {
            $service->setStatus($plan, $plan->status !== 'active', Auth::user());
        } catch (FieldRuleException $e) {
            $this->addError('workflow', $e->getMessage());

            return;
        }

        unset($this->planModel);
        session()->flash('status', 'Saved.');
    }

    public function render(): View
    {
        $plan = $this->planModel();

        return view('quality.plan-show', [
            'plan' => $plan, 'stages' => InspectionPlan::STAGES, 'canEdit' => Gate::allows('update', $plan), 'canToggle' => Gate::allows('toggle', $plan) && $plan->isApproved(),
            'canInspect' => Gate::allows('create', Inspection::class) && $plan->isUsable(),
            'history' => Gate::allows('viewAudit', $plan)
                ? ActivityLog::query()->with('causer')->where('subject_type', $plan->getMorphClass())->where('subject_id', $plan->id)->latest('id')->limit(30)->get()
                : collect(),
        ]);
    }
}
