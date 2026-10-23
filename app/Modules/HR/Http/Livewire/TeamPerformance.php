<?php

namespace App\Modules\HR\Http\Livewire;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\PerformanceGoal;
use App\Modules\HR\Models\PerformanceReview;
use App\Modules\HR\Models\ReviewCycle;
use App\Modules\HR\Services\PerformanceService;
use App\Modules\HR\Services\StaffReach;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The people the viewer manages (everyone, for HR) in a review cycle: where each review stands, and, for the person chosen, their goals (add, change, remove), the manager's
 * rating, and HR's final rating.
 */
#[Title('Team performance')]
class TeamPerformance extends Component
{
    use ActsAsStaff;

    #[Url(as: 'cycle')]
    public string $cycle = '';

    public int $selected = 0;

    /** @var array<string, string> */
    public array $goal = ['id' => '', 'title' => '', 'description' => '', 'weight' => ''];

    /** @var array<string, string> */
    public array $rate = ['rating' => '', 'comments' => ''];

    public string $finalRating = '';

    public function mount(): void
    {
        $user = $this->actor();
        abort_unless($user->can('performance.view') || $user->can('performance.view_all'), 403);
    }

    private function chosen(): ?ReviewCycle
    {
        $cycles = ReviewCycle::query()->whereIn('state', ['open', 'closed'])->orderByDesc('period_start')->get();

        return (ctype_digit($this->cycle) ? $cycles->firstWhere('id', (int) $this->cycle) : null) ?? $cycles->firstWhere('state', 'open') ?? $cycles->first();
    }

    private function person(int $id): Employee
    {
        return app(StaffReach::class)->employees($this->actor(), 'performance.view_all', 'performance.view')->findOrFail($id);
    }

    public function pick(int $id): void
    {
        $this->resetErrorBag();
        $this->selected = $this->selected === $id ? 0 : $id;
        $this->goal = ['id' => '', 'title' => '', 'description' => '', 'weight' => ''];
        $cycle = $this->chosen();
        $review = $this->selected !== 0 && $cycle !== null ? PerformanceReview::query()->where('employee_id', $id)->where('review_cycle_id', $cycle->id)->first() : null;
        $this->rate = ['rating' => (string) $review?->manager_rating, 'comments' => (string) $review?->manager_comments];
        $this->finalRating = $review === null || $review->manager_rating === null ? '' : (string) $review->manager_rating;
    }

    public function editGoal(int $goalId): void
    {
        $g = PerformanceGoal::query()->where('employee_id', $this->selected)->findOrFail($goalId);
        $this->goal = ['id' => (string) $g->id, 'title' => $g->title, 'description' => (string) $g->description, 'weight' => (string) $g->weight];
    }

    public function saveGoal(PerformanceService $service): void
    {
        $cycle = $this->chosen();
        abort_if($cycle === null || $this->selected === 0, 404);
        $employee = $this->person($this->selected);
        $editing = $this->goal['id'] === '' ? null : PerformanceGoal::query()->where('employee_id', $employee->id)->findOrFail((int) $this->goal['id']);

        if ($this->attempt(fn () => $service->saveGoal($employee, $cycle, $this->goal, $this->actor(), $editing), 'Goal saved.')) {
            $this->goal = ['id' => '', 'title' => '', 'description' => '', 'weight' => ''];
        }
    }

    public function removeGoal(PerformanceService $service, int $goalId): void
    {
        $goal = PerformanceGoal::query()->where('employee_id', $this->selected)->findOrFail($goalId);
        $this->attempt(fn () => $service->removeGoal($goal, $this->actor()), 'Goal removed.');
    }

    public function saveManagerReview(PerformanceService $service): void
    {
        $review = $this->reviewOf();

        if ($review === null) {
            $this->addError('action', 'There is no review to rate.');

            return;
        }

        $this->attempt(fn () => $service->managerReview($review, (int) $this->rate['rating'], $this->rate['comments'], $this->actor()), 'Your rating is saved. HR will make the review final.');
    }

    public function makeFinal(PerformanceService $service): void
    {
        $review = $this->reviewOf();

        if ($review === null) {
            $this->addError('action', 'There is no review to finalise.');

            return;
        }

        $this->attempt(fn () => $service->finalise($review, $this->finalRating === '' ? null : (int) $this->finalRating, $this->actor()), 'The review is final and the person has been told.');
    }

    private function reviewOf(): ?PerformanceReview
    {
        $cycle = $this->chosen();

        return $cycle === null || $this->selected === 0 ? null : PerformanceReview::query()->where('employee_id', $this->person($this->selected)->id)->where('review_cycle_id', $cycle->id)->first();
    }

    public function render(StaffReach $reach, PerformanceService $service): View
    {
        $user = $this->actor();
        $cycle = $this->chosen();
        $own = $this->ownEmployee();
        $people = $reach->employees($user, 'performance.view_all', 'performance.view')->when($own !== null && ! $user->can('performance.view_all'), fn ($q) => $q->where('employees.id', '!=', $own?->id))->orderBy('name')->get(['id', 'code', 'name']);
        $reviews = $cycle === null ? collect() : PerformanceReview::query()->where('review_cycle_id', $cycle->id)->whereIn('employee_id', $people->pluck('id'))->get()->keyBy('employee_id');
        $goalRows = $cycle === null ? collect() : PerformanceGoal::query()->where('review_cycle_id', $cycle->id)->whereIn('employee_id', $people->pluck('id'))->orderBy('id')->get()->groupBy('employee_id');

        $selected = $this->selected === 0 ? null : $people->firstWhere('id', $this->selected);
        $selectedEmployee = $selected === null ? null : Employee::query()->find($selected->id);

        return view('hr.team-performance', [
            'active' => $cycle, 'cycles' => ReviewCycle::query()->whereIn('state', ['open', 'closed'])->orderByDesc('period_start')->get(['id', 'name']), 'people' => $people, 'reviews' => $reviews, 'goalRows' => $goalRows,
            'scores' => $cycle === null ? [] : $people->mapWithKeys(fn ($p) => [$p->id => $service->score(Employee::query()->findOrFail($p->id), $cycle)])->all(),
            'chosen' => $selected, 'review' => $selected === null ? null : $reviews->get($selected->id), 'goals' => $selected === null ? collect() : $goalRows->get($selected->id, collect()),
            'canManage' => $selectedEmployee !== null && $service->manages($user, $selectedEmployee), 'canFinalise' => $user->can('performance.finalise'),
            'states' => PerformanceReview::STATES, 'ratings' => PerformanceReview::RATINGS,
            'workable' => $cycle !== null && $cycle->state === 'open',
        ]);
    }
}
