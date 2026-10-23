<?php

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\CRM\Enums\TargetPeriod;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\SalesTarget;
use App\Modules\CRM\Models\SalesTeam;
use App\Modules\Masters\Models\ItemGroup;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates and changes sales targets. The single gate for target data; it does not decide who may (SalesTargetPolicy
 * does) but it enforces the rules of the data:
 *
 *  - a target is for **one** salesperson or **one** team, for a calendar month or a quarter of the company's financial
 *    year (whatever day is given is moved to the start of its period), by value and/or quantity — both more than zero —
 *    optionally for one item group and/or one branch;
 *  - **no overlapping duplicates**: two targets for the same person or team with the same item group and branch may not
 *    cover any day in common — not the same month twice, and not October next to a Q3 that contains it — unless the
 *    target being saved is explicitly marked "allow overlap" (and an allowed one is likewise ignored when checking
 *    others). The check runs under a lock on the person or team, so two managers cannot both slip a target in;
 *    an exact duplicate is also refused by a unique key;
 *  - a person's target can be set by the people who manage them, a team's by the team's manager or by someone who sees
 *    everyone; a target whose period is over is history and is neither changed nor removed.
 *
 * Input: ['target' => [subject ('user'|'team'), user_id, sales_team_id, period_type, period_start, target_value,
 *                      target_quantity, item_group_id, location_id, allow_overlap, status, remarks]]
 */
final class TargetService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SalesScope $scope,
        private readonly SalesTeamService $teams,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function save(?SalesTarget $target, array $input, User $actor): SalesTarget
    {
        if ($target !== null && $target->isOver()) {
            throw new FieldRuleException('The period of this target is over, so it is history and cannot be changed.');
        }

        $companyId = (int) $this->tenant->activeCompanyId();
        $data = $this->normalise((array) ($input['target'] ?? []));
        $fyStart = (int) (DB::table('companies')->where('id', $companyId)->value('financial_year_start_month') ?: 4);

        $validator = Validator::make(['target' => $data], [
            'target.subject' => ['required', Rule::in(['user', 'team'])],
            'target.user_id' => ['nullable', 'integer', 'required_if:target.subject,user'],
            'target.sales_team_id' => ['nullable', 'integer', 'required_if:target.subject,team'],
            'target.period_type' => ['required', Rule::in(TargetPeriod::values())],
            'target.period_start' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'target.target_value' => ['nullable', 'bail', 'regex:/^\d{1,13}(\.\d{1,2})?$/', fn (string $a, mixed $v, \Closure $fail) => bccomp((string) $v, '0', 2) > 0 ? null : $fail('The value must be more than zero.')],
            'target.target_quantity' => ['nullable', 'bail', 'regex:/^\d{1,12}(\.\d{1,3})?$/', fn (string $a, mixed $v, \Closure $fail) => bccomp((string) $v, '0', 3) > 0 ? null : $fail('The quantity must be more than zero.')],
            'target.item_group_id' => ['nullable', 'integer'],
            'target.location_id' => ['nullable', 'integer'],
            'target.allow_overlap' => ['boolean'],
            'target.status' => ['required', Rule::in(['active', 'inactive'])],
            'target.remarks' => ['nullable', 'string', 'max:1000'],
        ], [
            'target.target_value.regex' => 'Enter the value as a number with at most 2 decimals, e.g. 500000.',
            'target.target_quantity.regex' => 'Enter the quantity as a number with at most 3 decimals.',
            'target.user_id.required_if' => 'Choose the salesperson.',
            'target.sales_team_id.required_if' => 'Choose the team.',
            'target.period_start.date_format' => 'Choose the month or quarter.',
        ], ['target.target_value' => 'value', 'target.target_quantity' => 'quantity', 'target.user_id' => 'salesperson', 'target.sales_team_id' => 'team', 'target.period_type' => 'period']);

        $validator->after(function (\Illuminate\Validation\Validator $v) use ($data, $actor) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            if ($data['target_value'] === null && $data['target_quantity'] === null) {
                $v->errors()->add('target.target_value', 'Set a value, a quantity, or both.');
            }

            $this->checkSubject($v, $data, $actor);
            $this->checkDimensions($v, $data);
        });

        $validator->validate();

        [$start, $end] = TargetPeriod::from($data['period_type'])->bounds(Carbon::parse($data['period_start']), $fyStart);

        return DB::transaction(function () use ($data, $target, $actor, $start, $end, $companyId, $fyStart) {
            $subjectIsUser = $data['subject'] === 'user';

            // One person or team at a time: the lock is what makes "no overlapping duplicates" true under a race.
            $subjectId = (int) ($subjectIsUser ? $data['user_id'] : $data['sales_team_id']);
            $subjectIsUser
                ? User::query()->whereKey($subjectId)->lockForUpdate()->first()
                : SalesTeam::query()->whereKey($subjectId)->lockForUpdate()->first();

            $overlapping = $data['allow_overlap'] ? new Collection : $this->overlapping(
                $subjectIsUser ? $subjectId : null, $subjectIsUser ? null : $subjectId,
                $data['item_group_id'] === null ? null : (int) $data['item_group_id'],
                $data['location_id'] === null ? null : (int) $data['location_id'],
                $start->toDateString(), $end->toDateString(), $target?->getKey(),
            );

            if ($overlapping->isNotEmpty()) {
                $first = $overlapping->first();
                $period = $first->period_type->describe($first->period_start, $fyStart);

                throw ValidationException::withMessages(['target.period_start' => "This overlaps an existing target for the same person or team and the same item group and branch: {$period}. Change the period, or tick “allow overlap” if both are meant to count."]);
            }

            $model = $target ?? new SalesTarget;
            $model->fill([
                'period_type' => $data['period_type'], 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
                'target_value' => $data['target_value'] === null ? null : bcadd($data['target_value'], '0', 2),
                'target_quantity' => $data['target_quantity'] === null ? null : bcadd($data['target_quantity'], '0', 3),
                'allow_overlap' => $data['allow_overlap'], 'status' => $data['status'], 'remarks' => $data['remarks'],
            ]);
            $model->forceFill([
                'user_id' => $subjectIsUser ? $subjectId : null,
                'sales_team_id' => $subjectIsUser ? null : $subjectId,
                'item_group_id' => $data['item_group_id'] === null ? null : (int) $data['item_group_id'],
                'location_id' => $data['location_id'] === null ? null : (int) $data['location_id'],
                'slot_key' => $data['allow_overlap'] ? null : sha1(implode('|', [
                    $companyId, $subjectIsUser ? 'u' : 't', $subjectId, $data['period_type'], $start->toDateString(), $data['item_group_id'] ?? 0, $data['location_id'] ?? 0,
                ])),
                'updated_by' => $actor->getKey(),
            ]);

            if ($target === null) {
                $model->forceFill(['company_id' => $companyId, 'created_by' => $actor->getKey()]);
            }

            try {
                $model->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['target.period_start' => 'The same target has just been entered by someone else.']);
            }

            return $model->refresh();
        });
    }

    /**
     * Remove a target whose period is not over. Its key is released so the slot can be used again; the audit log keeps it.
     *
     * @throws FieldRuleException
     */
    public function delete(SalesTarget $target): void
    {
        if ($target->isOver()) {
            throw new FieldRuleException('The period of this target is over, so it is history and cannot be removed.');
        }

        DB::transaction(function () use ($target) {
            $target->forceFill(['slot_key' => null])->save();
            $target->delete();
        });
    }

    /**
     * Targets for the same person or team and the same item group and branch whose days overlap [$from, $to]. A target
     * that is explicitly allowed to overlap is never a conflict.
     *
     * @return Collection<int, SalesTarget>
     */
    public function overlapping(?int $userId, ?int $teamId, ?int $itemGroupId, ?int $locationId, string $from, string $to, ?int $ignoreId = null): Collection
    {
        return SalesTarget::query()
            ->when($userId !== null, fn (Builder $q) => $q->where('user_id', $userId), fn (Builder $q) => $q->where('sales_team_id', $teamId))
            ->when($itemGroupId === null, fn (Builder $q) => $q->whereNull('item_group_id'), fn (Builder $q) => $q->where('item_group_id', $itemGroupId))
            ->when($locationId === null, fn (Builder $q) => $q->whereNull('location_id'), fn (Builder $q) => $q->where('location_id', $locationId))
            ->where('allow_overlap', false)
            ->where('period_start', '<=', $to)
            ->where('period_end', '>=', $from)
            ->when($ignoreId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->orderBy('period_start')
            ->get();
    }

    /** @param  array<string, mixed>  $data */
    private function checkSubject(\Illuminate\Validation\Validator $v, array $data, User $actor): void
    {
        if ($data['subject'] === 'user') {
            $userId = (int) $data['user_id'];

            if (! array_key_exists($userId, $this->teams->staff())) {
                $v->errors()->add('target.user_id', 'Choose an active user of this company.');
            } elseif (! $this->scope->manages($actor, $userId)) {
                $v->errors()->add('target.user_id', 'You can set targets only for the people on the teams you manage.');
            }

            return;
        }

        $team = SalesTeam::query()->find((int) $data['sales_team_id']);

        if ($team === null) {
            $v->errors()->add('target.sales_team_id', 'Choose a team.');
        } elseif (! $this->scope->runsTeam($actor, $team)) {
            $v->errors()->add('target.sales_team_id', 'You can set targets only for teams you manage.');
        }
    }

    /** @param  array<string, mixed>  $data */
    private function checkDimensions(\Illuminate\Validation\Validator $v, array $data): void
    {
        if ($data['item_group_id'] !== null && ItemGroup::query()->find((int) $data['item_group_id']) === null) {
            $v->errors()->add('target.item_group_id', 'Choose one of the company’s item groups.');
        }

        // Location carries the branch scope on its own id, so a branch the person does not work in is simply not found.
        if ($data['location_id'] !== null && Location::query()->find((int) $data['location_id']) === null) {
            $v->errors()->add('target.location_id', 'Choose one of your branches.');
        }
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    private function normalise(array $target): array
    {
        $text = fn (mixed $v): ?string => ($v = trim((string) preg_replace('/\s+/u', ' ', (string) ($v ?? '')))) === '' ? null : $v;
        $number = fn (mixed $v): ?string => ($v = $text(str_replace(',', '', (string) ($v ?? '')))) === null ? null : $v;
        $subject = $text($target['subject'] ?? null);

        return [
            'subject' => $subject,
            'user_id' => $subject === 'user' ? $text($target['user_id'] ?? null) : null,
            'sales_team_id' => $subject === 'team' ? $text($target['sales_team_id'] ?? null) : null,
            'period_type' => $text($target['period_type'] ?? null),
            'period_start' => $text($target['period_start'] ?? null),
            'target_value' => $number($target['target_value'] ?? null),
            'target_quantity' => $number($target['target_quantity'] ?? null),
            'item_group_id' => $text($target['item_group_id'] ?? null),
            'location_id' => $text($target['location_id'] ?? null),
            'allow_overlap' => filter_var($target['allow_overlap'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'status' => $text($target['status'] ?? null) ?? 'active',
            'remarks' => ($remarks = trim((string) ($target['remarks'] ?? ''))) === '' ? null : $remarks,
        ];
    }
}
