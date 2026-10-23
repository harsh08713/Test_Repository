<?php

namespace App\Modules\HR\Http\Livewire;

use App\Modules\HR\Models\PerformanceGoal;
use App\Modules\HR\Models\PerformanceReview;
use App\Modules\HR\Models\ReviewCycle;
use App\Modules\HR\Services\PerformanceService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** "My performance": the person's goals for a cycle (they update their own progress), their self-review, and what their manager and HR concluded once it is final. */
#[Title('My performance')]
class MyPerformance extends Component
{
    use ActsAsStaff;

    #[Url(as: 'cycle')]
    public string $cycle = '';

    /** @var array<int|string, string> goal id => progress typed */
    public array $progress = [];

    /** @var array<string, string> */
    public array $self = ['rating' => '', 'comments' => ''];

    public function saveProgress(PerformanceService $service, int $goalId): void
    {
        $employee = $this->ownEmployee();
        abort_if($employee === null, 403);
        $goal = PerformanceGoal::query()->where('employee_id', $employee->id)->findOrFail($goalId);
        $value = trim($this->progress[$goalId] ?? '');

        $this->attempt(fn () => $service->setProgress($goal, ctype_digit($value) ? (int) $value : -1, $this->actor()), 'Progress saved.');
    }

    public function saveSelfReview(PerformanceService $service): void
    {
        $employee = $this->ownEmployee();
        abort_if($employee === null, 403);
        $cycle = $this->chosen();
        $review = $cycle === null ? null : $service->ensureReview($employee, $cycle);

        if ($review === null) {
            $this->addError('action', 'There is no review for you in this cycle.');

            return;
        }

        $this->attempt(fn () => $service->selfReview($review, (int) $this->self['rating'], $this->self['comments'], $this->actor()), 'Your self-review is with your manager.');
    }

    private function chosen(): ?ReviewCycle
    {
        $cycles = ReviewCycle::query()->whereIn('state', ['open', 'closed'])->orderByDesc('period_start')->get();

        return (ctype_digit($this->cycle) ? $cycles->firstWhere('id', (int) $this->cycle) : null) ?? $cycles->firstWhere('state', 'open') ?? $cycles->first();
    }

    public function render(PerformanceService $service): View
    {
        $employee = $this->ownEmployee();
        $cycle = $this->chosen();
        $review = $employee !== null && $cycle !== null ? $service->ensureReview($employee, $cycle) : null;
        $goals = $employee !== null && $cycle !== null ? PerformanceGoal::query()->where('employee_id', $employee->id)->where('review_cycle_id', $cycle->id)->orderBy('id')->get() : collect();

        foreach ($goals as $g) {
            $this->progress[$g->id] ??= (string) $g->progress;
        }

        if ($review !== null && $this->self['rating'] === '' && $review->self_rating !== null) {
            $this->self = ['rating' => (string) $review->self_rating, 'comments' => (string) $review->self_comments];
        }

        return view('hr.my-performance', [
            'employee' => $employee, 'active' => $cycle, 'cycles' => ReviewCycle::query()->whereIn('state', ['open', 'closed'])->orderByDesc('period_start')->get(['id', 'name', 'state']),
            'review' => $review, 'goals' => $goals, 'score' => $employee !== null && $cycle !== null ? $service->score($employee, $cycle) : null,
            'ratings' => PerformanceReview::RATINGS, 'states' => PerformanceReview::STATES,
            'editable' => $cycle !== null && $cycle->state === 'open' && $review !== null && in_array($review->state, ['pending', 'self_done'], true),
        ]);
    }
}
