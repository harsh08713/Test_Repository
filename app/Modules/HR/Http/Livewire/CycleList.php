<?php

namespace App\Modules\HR\Http\Livewire;

use App\Modules\HR\Models\PerformanceReview;
use App\Modules\HR\Models\ReviewCycle;
use App\Modules\HR\Services\PerformanceService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Review cycles: create one, open it (a review is made for everyone), close it. */
#[Title('Review cycles')]
class CycleList extends Component
{
    use ActsAsStaff;

    public bool $formOpen = false;

    public ?int $editingId = null;

    /** @var array<string, string> */
    public array $f = ['code' => '', 'name' => '', 'period_start' => '', 'period_end' => ''];

    public function mount(): void
    {
        Gate::authorize('viewAny', ReviewCycle::class);
    }

    public function create(): void
    {
        Gate::authorize('create', ReviewCycle::class);
        $this->resetErrorBag();
        $this->editingId = null;
        $year = now()->month >= 4 ? now()->year : now()->year - 1;
        $this->f = ['code' => 'FY'.$year.'-'.substr((string) ($year + 1), 2), 'name' => 'Annual review '.$year.'–'.substr((string) ($year + 1), 2), 'period_start' => $year.'-04-01', 'period_end' => ($year + 1).'-03-31'];
        $this->formOpen = true;
    }

    public function edit(int $id): void
    {
        $c = ReviewCycle::query()->findOrFail($id);
        Gate::authorize('update', $c);
        $this->resetErrorBag();
        $this->editingId = $c->id;
        $this->f = ['code' => $c->code, 'name' => $c->name, 'period_start' => $c->period_start->toDateString(), 'period_end' => $c->period_end->toDateString()];
        $this->formOpen = true;
    }

    public function cancel(): void
    {
        $this->formOpen = false;
        $this->resetErrorBag();
    }

    public function save(PerformanceService $service): void
    {
        $editing = $this->editingId === null ? null : ReviewCycle::query()->findOrFail($this->editingId);

        if ($this->attempt(fn () => $service->saveCycle($this->f, $this->actor(), $editing), 'Cycle saved.')) {
            $this->formOpen = false;
        }
    }

    public function openIt(PerformanceService $service, int $id): void
    {
        $cycle = ReviewCycle::query()->findOrFail($id);
        $made = 0;

        $ok = $this->attempt(function () use ($service, $cycle, &$made) {
            $made = $service->openCycle($cycle, $this->actor());
        }, 'The cycle is open.');

        if ($ok) {
            session()->flash('status', 'The cycle is open: '.$made.' reviews were made.');
        }
    }

    public function closeIt(PerformanceService $service, int $id): void
    {
        $this->attempt(fn () => $service->closeCycle(ReviewCycle::query()->findOrFail($id), $this->actor()), 'The cycle is closed.');
    }

    public function render(): View
    {
        $cycles = ReviewCycle::query()->orderByDesc('period_start')->get();
        $counts = PerformanceReview::query()->whereIn('review_cycle_id', $cycles->pluck('id'))->toBase()->groupBy('review_cycle_id', 'state')->selectRaw('review_cycle_id, state, count(*) as n')->get();

        return view('hr.cycles', [
            'cycles' => $cycles, 'states' => ReviewCycle::STATES, 'total' => $counts->groupBy('review_cycle_id')->map(fn ($r) => (int) $r->sum('n')),
            'final' => $counts->where('state', 'finalised')->pluck('n', 'review_cycle_id'), 'canCreate' => Gate::allows('create', ReviewCycle::class), 'canEdit' => $this->actor()->can('review_cycle.edit'),
        ]);
    }
}
