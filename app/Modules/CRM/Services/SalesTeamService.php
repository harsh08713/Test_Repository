<?php

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\SalesTeam;
use App\Modules\CRM\Models\SalesTeamMember;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates teams and moves people on and off them. The single gate for team data; it does not decide who may (the
 * policies do) but it does keep the structure honest:
 *
 *  - a team's manager is an active user of the company, and unless the person saving can see everyone they can only
 *    run teams of which they are the manager;
 *  - a person is on **one team at a time**, and their memberships never overlap in time — the database backs this with
 *    a generated column, so two simultaneous requests cannot both add the same person;
 *  - leaving a team ends the membership (`left_on`, the last day, inclusive); nothing is deleted, so a past month's
 *    team can still be reconstructed.
 *
 * Input for saveTeam: ['team' => [code, name, manager_user_id, status, remarks]].
 */
final class SalesTeamService
{
    public function __construct(private readonly TenantContext $tenant, private readonly SalesScope $scope) {}

    /**
     * The people who can be a manager or a member: active users of the active company.
     *
     * @return array<int, string> id => name
     */
    public function staff(): array
    {
        $companyId = (int) $this->tenant->activeCompanyId();

        return User::query()
            ->where('is_active', true)
            ->whereIn('id', DB::table('company_user')->where('company_id', $companyId)->select('user_id'))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Who $actor may hand a lead to: themselves and the people they manage — anyone, for a holder of `sales.scope_all`.
     *
     * @return array<int, string> id => name
     */
    public function assignees(User $actor): array
    {
        $staff = $this->staff();
        $managed = $this->scope->managedUserIds($actor);

        return $managed === null ? $staff : array_intersect_key($staff, array_flip([...$managed, (int) $actor->getKey()]));
    }

    /**
     * The staff who work in one branch: their membership of the company covers every branch, or names this one. One
     * query for the whole list, so a form can offer the right people without asking about each of them.
     *
     * @return array<int, string> id => name
     */
    public function staffInBranch(int $locationId): array
    {
        $companyId = (int) $this->tenant->activeCompanyId();

        $inBranch = DB::table('company_user')->where('company_id', $companyId)
            ->where(fn ($q) => $q->where('all_locations', true)
                ->orWhereIn('user_id', DB::table('location_user')->where('location_id', $locationId)->select('user_id')))
            ->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        return array_intersect_key($this->staff(), array_flip($inBranch));
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function saveTeam(?SalesTeam $team, array $input, User $actor): SalesTeam
    {
        $companyId = (int) $this->tenant->activeCompanyId();
        $data = $this->normalise((array) ($input['team'] ?? []));

        $validator = Validator::make(['team' => $data], [
            'team.code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9._\-\/]*$/',
                Rule::unique('sales_teams', 'code')->where('company_id', $companyId)->ignore($team?->getKey())],
            'team.name' => ['required', 'string', 'max:150',
                Rule::unique('sales_teams', 'name')->where('company_id', $companyId)->ignore($team?->getKey())],
            'team.manager_user_id' => ['required', 'integer'],
            'team.status' => ['required', Rule::in(['active', 'inactive'])],
            'team.remarks' => ['nullable', 'string', 'max:1000'],
        ], [
            'team.code.regex' => 'Use letters, digits and . _ - / only.',
            'team.code.unique' => 'Another team already uses that code.',
            'team.name.unique' => 'Another team already has that name.',
        ], ['team.manager_user_id' => 'manager']);

        $validator->after(function (\Illuminate\Validation\Validator $v) use ($data, $actor) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $managerId = (int) $data['manager_user_id'];

            if (! array_key_exists($managerId, $this->staff())) {
                $v->errors()->add('team.manager_user_id', 'Choose an active user of this company as the manager.');
            } elseif (! $this->scope->seesEveryone($actor) && $managerId !== (int) $actor->getKey()) {
                $v->errors()->add('team.manager_user_id', 'You can only run teams of which you are the manager.');
            }
        });

        $validator->validate();

        return DB::transaction(function () use ($data, $team, $actor) {
            $model = $team ?? new SalesTeam;
            $model->fill(['code' => $data['code'], 'name' => $data['name'], 'status' => $data['status'], 'remarks' => $data['remarks']]);
            $model->forceFill(['manager_user_id' => (int) $data['manager_user_id'], 'updated_by' => $actor->getKey()]);

            if ($team === null) {
                $model->forceFill(['company_id' => (int) $this->tenant->activeCompanyId(), 'created_by' => $actor->getKey()]);
            }

            $model->save();

            return $model->refresh();
        });
    }

    /**
     * Put a person on a team from $joinedOn (default today; never in the future).
     *
     * @throws FieldRuleException
     * @throws ValidationException
     */
    public function addMember(SalesTeam $team, int $userId, ?string $joinedOn, User $actor): SalesTeamMember
    {
        $data = Validator::make(['user_id' => $userId, 'joined_on' => $joinedOn !== null && trim($joinedOn) !== '' ? trim($joinedOn) : now()->toDateString()], [
            'user_id' => ['required', 'integer'],
            'joined_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.now()->toDateString()],
        ], [
            'joined_on.before_or_equal' => 'A person cannot join a team in the future.',
            'joined_on.date_format' => 'Enter the day they join as a valid date.',
        ], ['user_id' => 'person'])->validate();

        if ($team->status !== 'active') {
            throw new FieldRuleException('This team is switched off, so nobody can be added to it.');
        }

        $staff = $this->staff();
        if (! array_key_exists($userId, $staff)) {
            throw ValidationException::withMessages(['user_id' => 'Choose an active user of this company.']);
        }

        if ($userId === (int) $team->manager_user_id) {
            throw ValidationException::withMessages(['user_id' => 'The manager of a team is not also one of its members.']);
        }

        return DB::transaction(function () use ($team, $userId, $data, $actor, $staff) {
            // Any membership that is still open, or that ended on or after the day this one starts, collides with it.
            $clash = SalesTeamMember::query()->with('team:id,name')
                ->where('user_id', $userId)
                ->where(fn ($q) => $q->whereNull('left_on')->orWhere('left_on', '>=', $data['joined_on']))
                ->orderByRaw('left_on is null desc')
                ->first();

            if ($clash !== null) {
                $name = $staff[$userId];
                $where = $clash->team->name ?? 'another team';

                throw ValidationException::withMessages(['user_id' => $clash->left_on === null
                    ? "{$name} is already on {$where}. Take them off it first: a person is on one team at a time."
                    : "{$name} was on {$where} until ".$clash->left_on->format('j M Y').', so they cannot join before the next day.']);
            }

            $member = new SalesTeamMember;
            $member->fill(['joined_on' => $data['joined_on']]);
            $member->forceFill(['company_id' => (int) $team->company_id, 'sales_team_id' => $team->getKey(), 'user_id' => $userId, 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey()]);

            try {
                $member->save();
            } catch (UniqueConstraintViolationException) {
                // two requests added the same person at once: the database's one-open-membership key caught the second
                throw ValidationException::withMessages(['user_id' => "{$staff[$userId]} has just been added to a team. Reload and check."]);
            }

            return $member->refresh();
        });
    }

    /**
     * End a membership on $leftOn (default today; the last day on the team, inclusive).
     *
     * @throws FieldRuleException
     * @throws ValidationException
     */
    public function removeMember(SalesTeamMember $member, ?string $leftOn, User $actor): SalesTeamMember
    {
        $leftOn = $leftOn !== null && trim($leftOn) !== '' ? trim($leftOn) : now()->toDateString();

        Validator::make(['left_on' => $leftOn], [
            'left_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now()->toDateString()],
        ], ['left_on.before_or_equal' => 'The last day on the team cannot be in the future.', 'left_on.date_format' => 'Enter the last day as a valid date.'])->validate();

        return DB::transaction(function () use ($member, $leftOn, $actor) {
            $fresh = SalesTeamMember::query()->lockForUpdate()->findOrFail($member->getKey());

            if ($fresh->left_on !== null) {
                throw new FieldRuleException('This person has already left the team.');
            }

            if ($leftOn < $fresh->joined_on->toDateString()) {
                throw ValidationException::withMessages(['left_on' => 'They cannot leave before they joined ('.$fresh->joined_on->format('j M Y').').']);
            }

            $fresh->fill(['left_on' => $leftOn])->forceFill(['updated_by' => $actor->getKey()])->save();

            return $fresh->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $team
     * @return array<string, mixed>
     */
    private function normalise(array $team): array
    {
        $text = fn (mixed $v): ?string => ($v = trim((string) preg_replace('/\s+/u', ' ', (string) ($v ?? '')))) === '' ? null : $v;

        return [
            'code' => ($code = $text($team['code'] ?? null)) === null ? null : strtoupper($code),
            'name' => $text($team['name'] ?? null),
            'manager_user_id' => $text($team['manager_user_id'] ?? null),
            'status' => $text($team['status'] ?? null) ?? 'active',
            'remarks' => ($remarks = trim((string) ($team['remarks'] ?? ''))) === '' ? null : $remarks,
        ];
    }
}
