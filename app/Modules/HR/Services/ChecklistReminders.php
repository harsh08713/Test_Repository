<?php

namespace App\Modules\HR\Services;

use App\Models\User;
use App\Modules\HR\Models\EmployeeChecklistItem;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Tells people about checklist lines that are late: one notice per person (a digest), for the lines they are to do — the employee's own, the manager's lines for their reports, and
 * HR's lines for everyone holding `checklist.edit`. A line is claimed by a conditional update of `reminded_at`, so it is told about once a week at most however many runs overlap, and
 * never twice by two runs at the same moment. Run inside a company.
 */
final class ChecklistReminders
{
    public const REPEAT_DAYS = 7;

    public function __construct(private readonly HrNotifier $notifier, private readonly TenantContext $tenant) {}

    /** @return int how many lines were reminded about */
    public function run(): int
    {
        $now = now();
        $today = $now->toDateString();
        $cutoff = $now->copy()->subDays(self::REPEAT_DAYS);
        $claimed = 0;

        /** @var array<int, array{user: User, count: int}> $digest */
        $digest = [];

        $late = EmployeeChecklistItem::query()->whereNull('done_at')->where('due_on', '<', $today)
            ->where(fn ($q) => $q->whereNull('reminded_at')->orWhere('reminded_at', '<=', $cutoff))
            ->with('employee')->orderBy('id')->limit(500)->get();

        foreach ($late as $item) {
            $employee = $item->employee;

            if ($employee === null || $employee->employment_status === 'exited') {
                continue;
            }

            $won = DB::table('employee_checklist_items')->where('id', $item->id)->whereNull('done_at')
                ->where(fn ($q) => $q->whereNull('reminded_at')->orWhere('reminded_at', '<=', $cutoff))->update(['reminded_at' => $now]);

            if ($won === 0) {
                continue;   // another run took it
            }

            $claimed++;

            foreach ($this->who($item) as $user) {
                $digest[$user->id] = ['user' => $user, 'count' => ($digest[$user->id]['count'] ?? 0) + 1];
            }
        }

        foreach ($digest as ['user' => $user, 'count' => $count]) {
            $this->notifier->tell($user, null, 'checklist_late', $count === 1 ? 'An onboarding or exit item is late' : $count.' onboarding or exit items are late',
                'Open the list to see what is waiting for you.', route('hr.checklists'));
        }

        return $claimed;
    }

    /** @return list<User> */
    private function who(EmployeeChecklistItem $item): array
    {
        $employee = $item->employee;

        if ($employee === null) {
            return [];
        }

        return match ($item->responsible) {
            'employee' => array_filter([$this->notifier->loginOf($employee)]),
            'manager' => array_filter([$this->notifier->managerOf($employee)]),
            default => User::permission('checklist.edit')->where('is_active', true)->get()->filter(fn (User $u) => $this->tenant->userCanAccessCompany($u, (int) $item->company_id))->values()->all(),
        };
    }
}
