<?php

namespace App\Modules\Quality\Http\Livewire;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Quality\Models\BatchHold;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformance;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** One inspection: the checks to fill in (the page works out pass or fail from the limits), completing it, deciding a failed one, cancelling, and what happened to the batch. */
#[Title('Inspection')]
class InspectionShow extends Component
{
    #[Locked]
    public int $inspectionId = 0;

    /** @var array<int, array<string, string>> result id => value / check / note */
    public array $entry = [];

    public string $dialog = '';

    public string $reason = '';

    public function mount(Inspection $inspection): void
    {
        Gate::authorize('view', $inspection);
        $this->inspectionId = $inspection->id;
        $this->load();
    }

    private function load(): void
    {
        $trim = fn ($v) => $v === null ? '' : (rtrim(rtrim((string) $v, '0'), '.') ?: '0');

        foreach ($this->inspectionModel()->results as $r) {
            $this->entry[$r->id] = ['value' => $trim($r->measured_value), 'check' => $r->kind === 'check' ? ($r->passed === null ? '' : ($r->passed ? 'pass' : 'fail')) : '', 'note' => (string) $r->note];
        }
    }

    #[Computed]
    public function inspectionModel(): Inspection
    {
        $inspection = Inspection::query()->with(['results', 'batch:id,batch_no,status,expiry_date', 'location:id,name', 'inspector:id,name', 'plan:id,code,name'])->findOrFail($this->inspectionId);
        Gate::authorize('view', $inspection);

        return $inspection;
    }

    public function saveResults(InspectionService $service): void
    {
        Gate::authorize('record', $this->inspectionModel());
        $this->run(fn () => $service->record($this->inspectionModel(), $this->entry, Auth::user()), 'Results saved.');
    }

    public function completeInspection(InspectionService $service): void
    {
        Gate::authorize('record', $this->inspectionModel());

        if ($this->run(function () use ($service) {
            $service->record($this->inspectionModel(), $this->entry, Auth::user());
            $service->complete($this->inspectionModel(), Auth::user());
        }, 'Inspection completed.')) {
            $this->load();
        }
    }

    public function openDialog(string $which): void
    {
        $inspection = $this->inspectionModel();
        Gate::authorize(match ($which) {
            'reject' => 'decide', 'deviation' => 'deviate', default => 'record'
        }, $inspection);
        $this->dialog = in_array($which, ['reject', 'deviation', 'cancel'], true) ? $which : '';
        $this->reason = '';
        $this->resetErrorBag();
    }

    public function closeDialog(): void
    {
        $this->dialog = '';
        $this->resetErrorBag();
    }

    public function finishDialog(InspectionService $service): void
    {
        $inspection = $this->inspectionModel();
        Gate::authorize(match ($this->dialog) {
            'reject' => 'decide', 'deviation' => 'deviate', default => 'record'
        }, $inspection);

        $done = $this->dialog === 'cancel'
            ? $this->run(fn () => $service->cancel($inspection, $this->reason, Auth::user()), 'The inspection is cancelled.')
            : $this->run(fn () => $service->decide($inspection, $this->dialog, $this->reason, Auth::user()), $this->dialog === 'reject' ? 'The lot is rejected and a non-conformance report is raised.' : 'The lot is accepted with a deviation and released.');

        $done && $this->dialog = '';
    }

    private function run(\Closure $call, string $done): bool
    {
        $this->resetErrorBag();

        try {
            $call();
        } catch (ValidationException $e) {
            $this->setErrorBag($e->errors());

            return false;
        } catch (FieldRuleException $e) {
            $this->addError('workflow', $e->getMessage());

            return false;
        }

        unset($this->inspectionModel);
        session()->flash('status', $done);

        return true;
    }

    public function render(): View
    {
        $inspection = $this->inspectionModel();

        return view('quality.inspection-show', [
            'inspection' => $inspection, 'states' => Inspection::STATES,
            'holds' => $inspection->batch_id === null ? collect() : BatchHold::query()->where('batch_id', $inspection->batch_id)->latest('id')->limit(10)->get(),
            'ncr' => NonConformance::query()->where('inspection_id', $inspection->id)->first(),
            'canRecord' => Gate::allows('record', $inspection), 'canDecide' => Gate::allows('decide', $inspection), 'canDeviate' => Gate::allows('deviate', $inspection),
            'history' => Gate::allows('viewAudit', $inspection)
                ? ActivityLog::query()->with('causer')->where('subject_type', $inspection->getMorphClass())->where('subject_id', $inspection->id)->latest('id')->limit(30)->get()
                : collect(),
        ]);
    }
}
