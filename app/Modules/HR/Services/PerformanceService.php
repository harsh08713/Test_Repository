<?php

namespace App\Modules\HR\Services;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\PerformanceGoal;
use App\Modules\HR\Models\PerformanceReview;
use App\Modules\HR\Models\ReviewCycle;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Review cycles, goals and reviews — the one door.
 *
 *   - A cycle is draft → open → closed. Opening it makes **one review for each person** who had joined by the cycle's end (once: unique per person and cycle).
 *   - Goals belong to a person in a cycle: at most ten, each with a weight in percent, the weights adding up to at most 100. Their manager line (or HR) sets and removes them; the person
 *     updates their own progress. Goals and progress can change only while the cycle is open and the review is still waiting for the person or the manager.
 *   - A review runs: the person rates themself (1–5) → their manager rates them (never the person) → HR makes it final (`performance.finalise`; by default the manager's rating).
 *     A final review does not change (the model refuses).
 *   - The weighted progress of a person's goals = Σ(weight × progress) ÷ Σ(weight).
 */
final class PerformanceService
{
    public const MAX_GOALS = 10;

    public function __construct(private readonly StaffReach $reach, private readonly EmployeeAccess $access, private readonly HrNotifier $notifier, private readonly TenantContext $tenant) {}

    private function company(): int
    {
        return (int) $this->tenant->activeCompanyId();
    }

    /** May this person set goals and rate the manager's part for `$employee`? Their line (never themself) or HR. */
    public function manages(User $user, Employee $employee): bool
    {
        return $this->reach->decides($user, $employee, 'performance.view_all', 'performance.edit');
    }

    // ---- cycles -----------------------------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input  code, name, period_start, period_end
     *
     * @throws ValidationException
     */
    public function saveCycle(array $input, User $actor, ?ReviewCycle $editing = null): ReviewCycle
    {
        if (! $actor->can($editing === null ? 'review_cycle.create' : 'review_cycle.edit')) {
            throw new AuthorizationException('You may not change review cycles.');
        }

        $errors = [];
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        $name = trim((string) ($input['name'] ?? ''));
        $start = $this->day((string) ($input['period_start'] ?? ''));
        $end = $this->day((string) ($input['period_end'] ?? ''));

        if (preg_match('/^[A-Z0-9._\/-]{1,30}$/', $code) !== 1) {
            $errors['code'] = 'Enter a code: letters, digits and . _ - / only (up to 30).';
        } elseif (ReviewCycle::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->company())->where('code', $code)->when($editing !== null, fn ($q) => $q->whereKeyNot($editing->id))->exists()) {
            $errors['code'] = 'That code is already used.';
        }

        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Enter the name of the cycle (up to 100 characters).';
        }

        if ($start === null || $end === null || $end->lt($start) || $start->diffInDays($end) > 400) {
            $errors['period_end'] = 'Give the first and last day of the period (the last not before the first, at most about a year).';
        }

        if ($editing !== null && $editing->state !== 'draft') {
            $errors['state'] = 'Only a draft cycle can be changed.';
        }

        if ($errors !== [] || $start === null || $end === null) {
            throw ValidationException::withMessages($errors);
        }

        $cycle = $editing ?? new ReviewCycle;
        $cycle->fill(['code' => $code, 'name' => $name, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString()]);

        if ($editing === null) {
            $cycle->forceFill(['state' => 'draft']);
        }

        $cycle->save();

        return $cycle;
    }

    /**
     * Open the cycle and make a review for everyone who had joined by its end.
     *
     * @return int reviews made
     *
     * @throws ValidationException
     */
    public function openCycle(ReviewCycle $cycle, User $actor): int
    {
        if (! $actor->can('review_cycle.edit')) {
            throw new AuthorizationException('You may not open a review cycle.');
        }

        if ($cycle->state !== 'draft') {
            throw ValidationException::withMessages(['state' => 'Only a draft cycle can be opened.']);
        }

        return DB::transaction(function () use ($cycle) {
            $cycle->forceFill(['state' => 'open'])->save();
            $made = 0;

            foreach (Employee::query()->whereIn('employment_status', ['active', 'notice'])->whereDate('joining_date', '<=', $cycle->period_end->toDateString())->get() as $employee) {
                $made += $this->ensureReview($employee, $cycle) !== null ? 1 : 0;
            }

            return $made;
        });
    }

    /** A review for the person in the cycle, made if it is not there (a person who joined after it was opened). */
    public function ensureReview(Employee $employee, ReviewCycle $cycle): ?PerformanceReview
    {
        $existing = PerformanceReview::query()->where('employee_id', $employee->id)->where('review_cycle_id', $cycle->id)->first();

        if ($existing !== null || $cycle->state !== 'open' || $employee->joining_date->gt($cycle->period_end) || $employee->employment_status === 'exited') {
            return $existing;
        }

        $review = new PerformanceReview;
        $review->forceFill(['company_id' => $employee->company_id, 'employee_id' => $employee->id, 'review_cycle_id' => $cycle->id, 'reviewer_id' => $this->notifier->managerOf($employee)?->id, 'state' => 'pending']);

        try {
            $review->save();
        } catch (UniqueConstraintViolationException) {
            return PerformanceReview::query()->where('employee_id', $employee->id)->where('review_cycle_id', $cycle->id)->first();
        }

        return $review;
    }

    /** @throws ValidationException */
    public function closeCycle(ReviewCycle $cycle, User $actor): ReviewCycle
    {
        if (! $actor->can('review_cycle.edit')) {
            throw new AuthorizationException('You may not close a review cycle.');
        }

        if ($cycle->state !== 'open') {
            throw ValidationException::withMessages(['state' => 'Only an open cycle can be closed.']);
        }

        $cycle->forceFill(['state' => 'closed'])->save();

        return $cycle;
    }

    // ---- goals ------------------------------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input  title, description, weight
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function saveGoal(Employee $employee, ReviewCycle $cycle, array $input, User $actor, ?PerformanceGoal $editing = null): PerformanceGoal
    {
        if (! $this->manages($actor, $employee)) {
            throw new AuthorizationException('Only their manager or HR can set goals.');
        }

        $this->mustBeWorkable($employee, $cycle);
        $errors = [];
        $title = trim((string) ($input['title'] ?? ''));
        $weight = trim((string) ($input['weight'] ?? ''));

        if ($title === '' || mb_strlen($title) > 200) {
            $errors['title'] = 'Say what the goal is (up to 200 characters).';
        }

        if (preg_match('/^\d{1,3}$/', $weight) !== 1 || (int) $weight < 1 || (int) $weight > 100) {
            $errors['weight'] = 'Give the weight as a percent from 1 to 100.';
        } else {
            $others = PerformanceGoal::query()->where('employee_id', $employee->id)->where('review_cycle_id', $cycle->id)->when($editing !== null, fn ($q) => $q->whereKeyNot($editing->id));

            if ((int) (clone $others)->sum('weight') + (int) $weight > 100) {
                $errors['weight'] = 'The weights of one person’s goals add up to at most 100: '.(100 - (int) (clone $others)->sum('weight')).' left.';
            }

            if ($editing === null && (clone $others)->count() >= self::MAX_GOALS) {
                $errors['title'] = 'At most '.self::MAX_GOALS.' goals for one person in a cycle.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $goal = $editing ?? new PerformanceGoal;
        $goal->fill(['title' => $title, 'description' => trim((string) ($input['description'] ?? '')) === '' ? null : mb_substr(trim((string) $input['description']), 0, 1000), 'weight' => (int) $weight]);

        if ($editing === null) {
            $goal->forceFill(['company_id' => $employee->company_id, 'employee_id' => $employee->id, 'review_cycle_id' => $cycle->id, 'progress' => 0]);
        }

        $goal->save();

        return $goal;
    }

    public function removeGoal(PerformanceGoal $goal, User $actor): void
    {
        $employee = Employee::query()->findOrFail($goal->employee_id);

        if (! $this->manages($actor, $employee)) {
            throw new AuthorizationException('Only their manager or HR can remove goals.');
        }

        $this->mustBeWorkable($employee, ReviewCycle::query()->findOrFail($goal->review_cycle_id));
        $goal->forceDelete();
    }

    /**
     * The person (or their manager line, or HR) says how far a goal has got.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function setProgress(PerformanceGoal $goal, int $progress, User $actor): PerformanceGoal
    {
        $employee = Employee::query()->findOrFail($goal->employee_id);

        if (! $this->access->isOwn($actor, $employee) && ! $this->manages($actor, $employee)) {
            throw new AuthorizationException('You may not change this goal.');
        }

        if ($progress < 0 || $progress > 100) {
            throw ValidationException::withMessages(['progress' => 'Progress is a percent from 0 to 100.']);
        }

        $this->mustBeWorkable($employee, ReviewCycle::query()->findOrFail($goal->review_cycle_id));
        $goal->forceFill(['progress' => $progress])->save();

        return $goal;
    }

    /** Σ(weight × progress) ÷ Σ(weight), as a percent with one decimal; null when there are no goals. */
    public function score(Employee $employee, ReviewCycle $cycle): ?string
    {
        $goals = PerformanceGoal::query()->where('employee_id', $employee->id)->where('review_cycle_id', $cycle->id)->get(['weight', 'progress']);
        $weights = (int) $goals->sum('weight');

        return $weights === 0 ? null : number_format($goals->sum(fn (PerformanceGoal $g) => $g->weight * $g->progress) / $weights, 1, '.', '');
    }

    // ---- reviews ----------------------------------------------------------------------------------------------------------------------

    /** @throws ValidationException */
    public function selfReview(PerformanceReview $review, int $rating, string $comments, User $actor): PerformanceReview
    {
        $employee = Employee::query()->findOrFail($review->employee_id);

        if (! $this->access->isOwn($actor, $employee)) {
            throw new AuthorizationException('Only the person rates themself.');
        }

        $this->rules($rating, $comments);

        return DB::transaction(function () use ($review, $rating, $comments, $employee) {
            $locked = PerformanceReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            $this->mustBeOpen($locked);

            if (! in_array($locked->state, ['pending', 'self_done'], true)) {
                throw ValidationException::withMessages(['state' => 'Your manager has already rated this review, so it cannot be changed.']);
            }

            $locked->forceFill(['state' => 'self_done', 'self_rating' => $rating, 'self_comments' => trim($comments)])->save();
            $this->notifier->tell($this->notifier->managerOf($employee), null, 'review_waiting', $employee->name.' has rated themself', $employee->name.' has done the self-review. It is waiting for your rating.', route('hr.team-performance'));

            return $locked;
        });
    }

    /** @throws ValidationException */
    public function managerReview(PerformanceReview $review, int $rating, string $comments, User $actor): PerformanceReview
    {
        $employee = Employee::query()->findOrFail($review->employee_id);

        if (! $this->manages($actor, $employee)) {
            throw new AuthorizationException($this->access->isOwn($actor, $employee) ? 'You cannot rate yourself as your own manager.' : 'Only their manager or HR can rate this review.');
        }

        $this->rules($rating, $comments);

        return DB::transaction(function () use ($review, $rating, $comments, $actor) {
            $locked = PerformanceReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            $this->mustBeOpen($locked);

            if (! in_array($locked->state, ['self_done', 'manager_done'], true)) {
                throw ValidationException::withMessages(['state' => $locked->state === 'pending' ? 'The person has not done their self-review yet.' : 'This review is already final.']);
            }

            $locked->forceFill(['state' => 'manager_done', 'manager_rating' => $rating, 'manager_comments' => trim($comments), 'reviewer_id' => $actor->id])->save();

            return $locked;
        });
    }

    /** HR makes the review final. @throws ValidationException */
    public function finalise(PerformanceReview $review, ?int $finalRating, User $actor): PerformanceReview
    {
        if (! $actor->can('performance.finalise')) {
            throw new AuthorizationException('You may not finalise reviews.');
        }

        if ($finalRating !== null && ($finalRating < 1 || $finalRating > 5)) {
            throw ValidationException::withMessages(['final_rating' => 'Rate from 1 to 5.']);
        }

        $done = DB::transaction(function () use ($review, $finalRating, $actor) {
            $locked = PerformanceReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();

            if ($locked->state !== 'manager_done') {
                throw ValidationException::withMessages(['state' => 'Only a review the manager has rated can be made final.']);
            }

            $locked->forceFill(['state' => 'finalised', 'final_rating' => $finalRating ?? $locked->manager_rating, 'finalised_at' => now(), 'finalised_by' => $actor->id])->save();

            return $locked;
        });

        $employee = Employee::query()->find($done->employee_id);

        if ($employee !== null) {
            $this->notifier->tell($this->notifier->loginOf($employee), $actor, 'review_final', 'Your review is final', 'Your review for the cycle is final: '.$done->final_rating.' of 5, '.strtolower(PerformanceReview::RATINGS[(int) $done->final_rating] ?? '').'.', route('hr.my-performance'));
        }

        return $done;
    }

    // ---- internals -------------------------------------------------------------------------------------------------------------------

    private function rules(int $rating, string $comments): void
    {
        $errors = [];

        if ($rating < 1 || $rating > 5) {
            $errors['rating'] = 'Rate from 1 to 5.';
        }

        if (mb_strlen(trim($comments)) < 10 || mb_strlen(trim($comments)) > 2000) {
            $errors['comments'] = 'Say why, in 10 to 2000 characters.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function mustBeOpen(PerformanceReview $review): void
    {
        if (ReviewCycle::query()->whereKey($review->review_cycle_id)->value('state') !== 'open') {
            throw ValidationException::withMessages(['state' => 'The review cycle is not open.']);
        }
    }

    /** Goals and progress change only while the cycle is open and the review is still waiting for the person or the manager. */
    private function mustBeWorkable(Employee $employee, ReviewCycle $cycle): void
    {
        if ($cycle->state !== 'open') {
            throw ValidationException::withMessages(['state' => 'The review cycle is not open.']);
        }

        $review = PerformanceReview::query()->where('employee_id', $employee->id)->where('review_cycle_id', $cycle->id)->first();

        if ($review !== null && in_array($review->state, ['manager_done', 'finalised'], true)) {
            throw ValidationException::withMessages(['state' => 'The manager has already rated this review: the goals are fixed.']);
        }

        if ($employee->isExited()) {
            throw ValidationException::withMessages(['state' => 'This person has left the company.']);
        }
    }

    private function day(string $value): ?CarbonImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? CarbonImmutable::instance($parsed) : null;
    }
}
