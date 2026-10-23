<?php

namespace App\Modules\Loss\Http\Livewire;

use App\Models\User;
use App\Modules\Loss\Models\SavingsMeasurement;
use App\Modules\Loss\Services\SavingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * One savings measurement: the loss records it compares, the figures (previewed as the draft is typed), the evidence, and its steps — save, send to
 * Finance, verify or reject (not by who measured it or owns the action), re-open a rejected one.
 */
#[Title('Savings measurement')]
class SavingsShow extends Component
{
    public int $measurementId = 0;

    /** @var array<string, string> */
    public array $form = [];

    public string $notes = '';

    public function mount(int $measurement): void
    {
        $model = SavingsMeasurement::query()->findOrFail($measurement);
        Gate::authorize('view', $model);

        $this->measurementId = $model->id;
        $this->syncForm($model);
    }

    public function saveDraft(SavingsService $savings): void
    {
        $this->resetErrorBag();
        $this->attempt(function () use ($savings) {
            $savings->update($this->model(), $this->form, $this->user());
            session()->flash('status', 'Draft saved. The figures were worked out again from the loss records.');
        });
    }

    public function submitForFinance(SavingsService $savings): void
    {
        $this->resetErrorBag();
        $this->attempt(function () use ($savings) {
            $savings->update($this->model(), $this->form, $this->user());
            $savings->submit($this->model(), $this->user());
            session()->flash('status', 'Sent to Finance. It can no longer be changed.');
        });
    }

    public function verify(SavingsService $savings): void
    {
        $this->resetErrorBag();
        $this->attempt(function () use ($savings) {
            $savings->approve($this->model(), $this->notes, $this->user());
            $this->notes = '';
            session()->flash('status', 'Verified by Finance.');
        });
    }

    public function rejectMeasurement(SavingsService $savings): void
    {
        $this->resetErrorBag();
        $this->attempt(function () use ($savings) {
            $savings->reject($this->model(), $this->notes, $this->user());
            $this->notes = '';
            session()->flash('status', 'Rejected.');
        });
    }

    public function reopenDraft(SavingsService $savings): void
    {
        $this->resetErrorBag();
        $this->attempt(function () use ($savings) {
            $savings->reopen($this->model(), $this->user());
            session()->flash('status', 'Back to a draft.');
        });
    }

    public function render(SavingsService $savings): View
    {
        $m = $this->model()->load(['action.owner:id,name', 'action.incident:id,incident_number,title', 'category:id,name', 'location:id,name', 'maker:id,name', 'decider:id,name', 'recurrenceIncident:id,incident_number']);
        $calc = null;

        if ($m->state === 'draft') {
            try {
                $calc = $savings->preview($m, $this->form);
            } catch (ValidationException) {
                $calc = null;   // still being typed
            }
        }

        return view('loss.savings-show', [
            'measurement' => $m,
            'calc' => $calc,
            'can' => ['edit' => Gate::allows('update', $m), 'decide' => Gate::allows('decide', $m), 'reopen' => $m->state === 'rejected' && Gate::allows('create', SavingsMeasurement::class)],
        ]);
    }

    private function attempt(callable $do): void
    {
        try {
            $do();
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError(in_array($key, ['notes', 'measurement', 'action'], true) ? $key : 'form.'.$key, $messages[0]);
            }
        }

        $this->syncForm($this->model());
    }

    private function syncForm(SavingsMeasurement $m): void
    {
        $this->form = [
            'baseline_from' => $m->baseline_from->toDateString(), 'baseline_to' => $m->baseline_to->toDateString(), 'measure_from' => $m->measure_from->toDateString(),
            'measure_to' => $m->measure_to->toDateString(), 'baseline_volume' => (string) ($m->baseline_volume ?? ''), 'measure_volume' => (string) ($m->measure_volume ?? ''),
            'volume_unit' => (string) ($m->volume_unit ?? ''), 'implementation_cost' => (string) $m->implementation_cost, 'evidence' => (string) ($m->evidence ?? ''),
        ];
    }

    private function model(): SavingsMeasurement
    {
        return SavingsMeasurement::query()->findOrFail($this->measurementId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
