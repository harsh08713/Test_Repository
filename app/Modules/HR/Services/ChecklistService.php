<?php

namespace App\Modules\HR\Services;

use App\Models\User;
use App\Modules\HR\Models\ChecklistTemplateItem;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeChecklistItem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Onboarding and exit checklists — the one door.
 *
 *   - `start()` copies the company's active standard list for a kind onto a person, once: it takes the employee row lock first and the unique key
 *     (employee, kind, source line) is the backstop, so asking twice (a hire and a button press) makes the list once. Lines added to the standard list
 *     later are added on the next start; lines already made are never changed or doubled.
 *   - `tick()` marks a line done: HR (`checklist.edit`) for any line; the person's reporting manager (anyone above them in the line, never the person themself)
 *     for the manager's lines; the person themself for their own lines. A finished line is not changed.
 */
final class ChecklistService
{
    public function __construct(private readonly EmployeeAccess $access) {}

    /** @return int how many lines were added (0 when the list was already there or the standard list is empty) */
    public function start(Employee $employee, string $kind): int
    {
        if (! array_key_exists($kind, ChecklistTemplateItem::KINDS)) {
            throw ValidationException::withMessages(['kind' => 'Choose onboarding or exit.']);
        }

        return DB::transaction(function () use ($employee, $kind) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

            $have = EmployeeChecklistItem::query()->where('employee_id', $employee->id)->where('kind', $kind)->pluck('source_item_id')->all();
            $base = $kind === 'exit' ? ($employee->exit_date ?? now()) : $employee->joining_date;
            $added = 0;

            foreach (ChecklistTemplateItem::query()->where('kind', $kind)->where('status', 'active')->orderBy('due_days')->orderBy('id')->get() as $line) {
                if (in_array($line->id, $have, true)) {
                    continue;
                }

                $item = new EmployeeChecklistItem;
                $item->forceFill([
                    'company_id' => $employee->company_id, 'employee_id' => $employee->id, 'kind' => $kind, 'source_item_id' => $line->id, 'title' => $line->name,
                    'responsible' => $line->responsible, 'due_on' => $kind === 'exit' ? now()->addDays($line->due_days)->toDateString() : $base->copy()->addDays($line->due_days)->toDateString(),
                ])->save();
                $added++;
            }

            return $added;
        });
    }

    /** May this person mark this line done? */
    public function canTick(User $user, EmployeeChecklistItem $item): bool
    {
        if ($user->can('checklist.edit')) {
            return true;
        }

        $employee = $item->employee;
        $own = $this->access->own($user);

        if ($employee === null || $own === null) {
            return false;
        }

        return match ($item->responsible) {
            'employee' => $employee->id === $own->id,
            'manager' => $employee->id !== $own->id && in_array($employee->id, $this->access->belowIds($own->id), true),
            default => false,
        };
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function tick(EmployeeChecklistItem $item, User $actor, ?string $note = null): EmployeeChecklistItem
    {
        $note = $note === null ? null : trim($note);

        if ($note !== null && mb_strlen($note) > 300) {
            throw ValidationException::withMessages(['note' => 'Keep the note to 300 characters.']);
        }

        return DB::transaction(function () use ($item, $actor, $note) {
            $fresh = EmployeeChecklistItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if (! $this->canTick($actor, $fresh)) {
                throw new AuthorizationException('That item is not yours to mark done.');
            }

            if ($fresh->isDone()) {
                throw ValidationException::withMessages(['item' => 'That item is already done.']);
            }

            $fresh->forceFill(['done_at' => now(), 'done_by' => $actor->id, 'note' => $note === '' ? null : $note])->save();

            return $fresh;
        });
    }

    /**
     * The lines a person may see: HR sees all; anyone else sees their own list and those of the people below them in the reporting line.
     *
     * @return Builder<EmployeeChecklistItem>
     */
    public function visibleTo(User $user): Builder
    {
        $query = EmployeeChecklistItem::query();

        return $user->can('checklist.view') ? $query : $query->whereIn('employee_id', $this->access->reachIds($user) ?: [0]);
    }

    /** @return array{open: int, done: int, late: int} */
    public function progress(Employee $employee, string $kind): array
    {
        $rows = EmployeeChecklistItem::query()->where('employee_id', $employee->id)->where('kind', $kind)->get(['done_at', 'due_on']);

        return [
            'open' => $rows->whereNull('done_at')->count(), 'done' => $rows->whereNotNull('done_at')->count(),
            'late' => $rows->whereNull('done_at')->filter(fn ($r) => $r->due_on->lt(now()->startOfDay()))->count(),
        ];
    }
}
