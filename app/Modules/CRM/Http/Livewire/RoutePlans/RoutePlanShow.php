<?php

namespace App\Modules\CRM\Http\Livewire\RoutePlans;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Http\Livewire\Concerns\ScopesToPeople;
use App\Modules\CRM\Models\GeofenceViolation;
use App\Modules\CRM\Models\RoutePlan;
use App\Modules\CRM\Services\RoutePlanService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** One day's plan: its stops on the map, the attendance marked against it and the attempts that were refused. */
#[Title('Route plan')]
class RoutePlanShow extends Component
{
    use ScopesToPeople;

    #[Locked]
    public int $planId = 0;

    public bool $cancelOpen = false;

    public string $reason = '';

    public function mount(RoutePlan $plan): void
    {
        Gate::authorize('view', $plan);

        $this->planId = $plan->id;
    }

    #[Computed]
    public function planModel(): RoutePlan
    {
        $plan = RoutePlan::query()->with(['user:id,name', 'location:id,name', 'stops', 'attendance.overrider:id,name'])->findOrFail($this->planId);
        Gate::authorize('view', $plan);

        return $plan;
    }

    public function publish(RoutePlanService $service): void
    {
        $plan = $this->planModel();
        Gate::authorize('publish', $plan);

        $this->run(fn () => $service->publish($plan, $this->actor()), 'Published. '.$plan->user?->name.' can now mark attendance against this plan.');
    }

    public function reinstate(RoutePlanService $service): void
    {
        $plan = $this->planModel();
        Gate::authorize('reinstate', $plan);

        $this->run(fn () => $service->reinstate($plan, $this->actor()), 'Reinstated as a draft. Publish it again when it is ready.');
    }

    public function openCancel(): void
    {
        Gate::authorize('cancel', $this->planModel());

        $this->reason = '';
        $this->resetErrorBag();
        $this->cancelOpen = true;
    }

    public function closeCancel(): void
    {
        $this->cancelOpen = false;
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function cancel(RoutePlanService $service): void
    {
        $plan = $this->planModel();
        Gate::authorize('cancel', $plan);

        $this->run(fn () => $service->cancel($plan, $this->reason, $this->actor()), 'The plan is cancelled.');
    }

    public function delete(RoutePlanService $service): void
    {
        $plan = $this->planModel();
        Gate::authorize('delete', $plan);

        try {
            $service->delete($plan);
        } catch (FieldRuleException $e) {
            $this->addError('workflow', $e->getMessage());

            return;
        }

        session()->flash('status', 'Plan deleted.');
        $this->redirectRoute('field.plans.index', navigate: false);
    }

    /** @param  callable(): mixed  $action */
    private function run(callable $action, string $done): void
    {
        try {
            $action();
        } catch (FieldRuleException $e) {
            $this->addError('workflow', $e->getMessage());

            return;
        } catch (ValidationException $e) {
            $this->addError('reason', (string) $e->validator->errors()->first());

            return;
        }

        unset($this->planModel);
        $this->closeCancel();
        session()->flash('status', $done);
        $this->redirectRoute('field.plans.show', $this->planId, navigate: false);
    }

    public function render(): View
    {
        $plan = $this->planModel();

        return view('crm.plans.show', [
            'plan' => $plan,
            'canEdit' => Gate::allows('update', $plan),
            'canPublish' => Gate::allows('publish', $plan),
            'canCancel' => Gate::allows('cancel', $plan),
            'canReinstate' => Gate::allows('reinstate', $plan),
            'canDelete' => Gate::allows('delete', $plan),
            'attempts' => Gate::allows('viewAny', GeofenceViolation::class)
                ? GeofenceViolation::query()->where('route_plan_id', $plan->getKey())->orderByDesc('id')->limit(20)->get()
                : collect(),
        ]);
    }
}
