<?php

namespace App\Modules\Loss\Http\Livewire;

use App\Models\User;
use App\Modules\Loss\Models\CorrectiveAction;
use App\Modules\Loss\Models\SavingsMeasurement;
use App\Modules\Loss\Services\LossFigures;
use App\Modules\Loss\Services\SavingsService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Verified savings: which corrective actions have had their saving measured, what Finance has verified (kept apart from the action's own "verified
 * result"), and what has come back. "Measure" starts a draft for an action somebody has verified; the draft is worked on from its own page.
 */
#[Title('Verified savings')]
class SavingsList extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    /** awaiting | draft | submitted | verified | recurred | rejected | all */
    #[Url(as: 'show')]
    public string $show = 'submitted';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->can('savings_measurement.view'), 403);

        $this->from = $this->from !== '' ? $this->from : now()->startOfYear()->toDateString();
        $this->to = $this->to !== '' ? $this->to : now()->toDateString();
    }

    public function updatingShow(): void
    {
        $this->resetPage();
    }

    public function startMeasurement(int $actionId, SavingsService $savings): void
    {
        $this->resetErrorBag();
        $action = CorrectiveAction::query()->findOrFail($actionId);
        $measurement = $savings->start($action, Auth::user());

        $this->redirect(route('loss.savings.show', $measurement->id), navigate: true);
    }

    public function render(SavingsService $savings, LossFigures $figures): View
    {
        $from = $this->date($this->from, now()->startOfYear());
        $to = $this->date($this->to, now());

        $measurements = SavingsMeasurement::query()
            ->when($this->show === 'recurred', fn ($q) => $q->whereNotNull('recurred_at'))
            ->when(in_array($this->show, ['draft', 'submitted', 'verified', 'rejected'], true), fn ($q) => $q->where('state', $this->show))
            ->when($this->show === 'awaiting', fn ($q) => $q->whereRaw('1 = 0'))
            ->with(['action:id,action_number,title,owner_id', 'category:id,name', 'location:id,name', 'maker:id,name', 'decider:id,name'])
            ->orderByDesc('id')->paginate(self::PER_PAGE);

        $awaiting = $savings->awaiting();

        return view('loss.savings', [
            'measurements' => $measurements,
            'awaiting' => $this->show === 'awaiting' ? $awaiting : collect(),
            'awaitingCount' => $awaiting->count(),
            'verified' => $figures->financeVerifiedSavings($from, $to),
            'tabs' => ['awaiting' => 'To measure', 'draft' => 'Drafts', 'submitted' => 'Waiting for Finance', 'verified' => 'Finance-verified', 'recurred' => 'Recurred', 'rejected' => 'Rejected', 'all' => 'All'],
            'canMeasure' => Gate::allows('create', SavingsMeasurement::class),
        ]);
    }

    private function date(string $value, CarbonInterface $default): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: CarbonImmutable::instance($default);
        } catch (Throwable) {
            return CarbonImmutable::instance($default);
        }
    }
}
