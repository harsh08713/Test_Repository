<?php

namespace App\Modules\Quality\Http\Livewire;

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Loss\Models\CorrectiveAction;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Quality\Models\BatchHold;
use App\Modules\Quality\Models\NonConformance;
use App\Modules\Quality\Services\NonConformanceService;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** One non-conformance report: what happened, the decision about the goods, the loss incident and corrective action it is linked to, and closing it. */
#[Title('Non-conformance')]
class NcrShow extends Component
{
    #[Locked]
    public int $ncrId = 0;

    public string $disposition = '';

    public string $dispositionNote = '';

    public string $incidentId = '';

    public string $actionId = '';

    public bool $closing = false;

    public string $closingNote = '';

    public function mount(NonConformance $ncr): void
    {
        Gate::authorize('view', $ncr);
        $this->ncrId = $ncr->id;
        $this->disposition = (string) $ncr->disposition;
        $this->dispositionNote = (string) $ncr->disposition_note;
        $this->incidentId = (string) $ncr->loss_incident_id;
        $this->actionId = (string) $ncr->corrective_action_id;
    }

    #[Computed]
    public function ncrModel(): NonConformance
    {
        $ncr = NonConformance::query()->with(['item:id,name', 'batch:id,batch_no,status', 'location:id,name', 'inspection:id,inspection_number', 'lossIncident:id,incident_number,title', 'correctiveAction:id,action_number,title'])->findOrFail($this->ncrId);
        Gate::authorize('view', $ncr);

        return $ncr;
    }

    public function decide(NonConformanceService $service): void
    {
        Gate::authorize('update', $this->ncrModel());
        $this->run(fn () => $service->disposition($this->ncrModel(), $this->disposition, $this->dispositionNote, Auth::user()), 'The decision is recorded.');
    }

    public function linkRecords(NonConformanceService $service): void
    {
        Gate::authorize('update', $this->ncrModel());
        $this->run(fn () => $service->link($this->ncrModel(), ctype_digit($this->incidentId) ? (int) $this->incidentId : null, ctype_digit($this->actionId) ? (int) $this->actionId : null, Auth::user()), 'Linked.');
    }

    public function updatedIncidentId(): void
    {
        $this->actionId = '';
    }

    public function openClose(): void
    {
        Gate::authorize('close', $this->ncrModel());
        $this->closing = true;
        $this->closingNote = '';
        $this->resetErrorBag();
    }

    public function cancelClose(): void
    {
        $this->closing = false;
        $this->resetErrorBag();
    }

    public function closeReport(NonConformanceService $service): void
    {
        Gate::authorize('close', $this->ncrModel());

        if ($this->run(fn () => $service->close($this->ncrModel(), $this->closingNote, Auth::user()), 'The report is closed.')) {
            $this->closing = false;
        }
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

        unset($this->ncrModel);
        session()->flash('status', $done);

        return true;
    }

    public function render(): View
    {
        $ncr = $this->ncrModel();

        return view('quality.ncr-show', [
            'ncr' => $ncr, 'states' => NonConformance::STATES, 'severities' => NonConformance::SEVERITIES, 'dispositions' => NonConformance::DISPOSITIONS,
            'incidents' => LossIncident::query()->orderByDesc('id')->limit(50)->get(['id', 'incident_number', 'title'])->mapWithKeys(fn ($i) => [$i->id => $i->incident_number.' · '.$i->title])->all(),
            'actions' => ctype_digit($this->incidentId) ? CorrectiveAction::query()->where('loss_incident_id', (int) $this->incidentId)->orderByDesc('id')->get(['id', 'action_number', 'title'])->mapWithKeys(fn ($a) => [$a->id => $a->action_number.' · '.$a->title])->all() : [],
            'holds' => $ncr->batch_id === null ? collect() : BatchHold::query()->where('batch_id', $ncr->batch_id)->latest('id')->limit(10)->get(),
            'canEdit' => Gate::allows('update', $ncr), 'canClose' => Gate::allows('close', $ncr),
            'history' => Gate::allows('viewAudit', $ncr)
                ? ActivityLog::query()->with('causer')->where('subject_type', $ncr->getMorphClass())->where('subject_id', $ncr->id)->latest('id')->limit(30)->get()
                : collect(),
        ]);
    }
}
