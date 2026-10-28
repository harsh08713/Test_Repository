<?php

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\CRM\Enums\CoverageKind;
use App\Modules\CRM\Models\Territory;
use App\Modules\CRM\Models\TerritoryArea;
use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Models\Block;
use App\Modules\Masters\Models\District;
use App\Modules\Masters\Models\State;
use App\Modules\Masters\Services\LedgerLookup;
use App\Modules\Masters\Services\PinCodeLookup;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Territory data has one door. It does not decide who may (the policy does) but it keeps the structure honest:
 *
 *  - a code and a name are the company's own, once each; an owner is an active user of the company (of the branch, when the territory names one);
 *  - a unit of the map — a state, a district, a block, a PIN code, a customer — belongs to **one** territory: the database's unique key is the
 *    backstop, and a second territory asking for it is told which one holds it;
 *  - a PIN code is covered only if the company's geography knows it, a customer only if it is an approved, active customer ledger (`LedgerLookup`);
 *  - every change of coverage or owner is written to the territory's audit trail.
 *
 * Input for `save`: ['code', 'name', 'location_id', 'owner_user_id', 'status', 'remarks'].
 */
final class TerritoryService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SalesTeamService $teams,
        private readonly LedgerLookup $ledgers,
        private readonly PinCodeLookup $pins,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function save(?Territory $territory, array $input, User $actor): Territory
    {
        $companyId = (int) $this->tenant->activeCompanyId();
        $data = [
            'code' => strtoupper(trim((string) ($input['code'] ?? ''))),
            'name' => trim((string) ($input['name'] ?? '')),
            'location_id' => $this->blank($input['location_id'] ?? null),
            'owner_user_id' => $this->blank($input['owner_user_id'] ?? null),
            'status' => (string) ($input['status'] ?? 'active'),
            'remarks' => $this->blank($input['remarks'] ?? null),
        ];

        Validator::make($data, [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9._\-\/]*$/', Rule::unique('territories', 'code')->where('company_id', $companyId)->ignore($territory?->getKey())],
            'name' => ['required', 'string', 'max:150', Rule::unique('territories', 'name')->where('company_id', $companyId)->whereNull('deleted_at')->ignore($territory?->getKey())],
            'location_id' => ['nullable', 'integer'],
            'owner_user_id' => ['nullable', 'integer'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ], [
            'code.regex' => 'Use letters, digits and . _ - / only.',
            'code.unique' => 'Another territory already uses that code.',
            'name.unique' => 'Another territory already has that name.',
        ], ['location_id' => 'branch', 'owner_user_id' => 'owner'])->after(function (\Illuminate\Validation\Validator $v) use ($data, $actor, $companyId) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            if ($data['location_id'] !== null) {
                $reach = $this->tenant->accessibleLocationIdsFor($actor, $companyId);
                $branch = Location::query()->find((int) $data['location_id']);

                if ($branch === null || $branch->status !== 'active' || ($reach !== null && ! in_array((int) $branch->getKey(), $reach, true))) {
                    $v->errors()->add('location_id', 'Choose one of your active branches, or leave it company-wide.');

                    return;
                }
            }

            if ($data['owner_user_id'] !== null) {
                $people = $data['location_id'] === null ? $this->teams->staff() : $this->teams->staffInBranch((int) $data['location_id']);

                if (! array_key_exists((int) $data['owner_user_id'], $people)) {
                    $v->errors()->add('owner_user_id', $data['location_id'] === null ? 'Choose an active user of this company.' : 'Choose an active user who works in that branch.');
                }
            }
        })->validate();

        return DB::transaction(function () use ($territory, $data, $actor, $companyId) {
            $model = $territory === null ? new Territory : Territory::query()->lockForUpdate()->findOrFail($territory->getKey());
            $model->fill(['code' => $data['code'], 'name' => $data['name'], 'status' => $data['status'], 'remarks' => $data['remarks']]);
            $model->forceFill([
                'location_id' => $data['location_id'] === null ? null : (int) $data['location_id'],
                'owner_user_id' => $data['owner_user_id'] === null ? null : (int) $data['owner_user_id'],
                'updated_by' => $actor->getKey(),
            ]);

            if ($territory === null) {
                $model->forceFill(['company_id' => $companyId, 'created_by' => $actor->getKey()]);
            }

            $model->save();

            return $model->refresh();
        });
    }

    /**
     * Give a territory a unit of the map.
     *
     * @param  string|int  $reference  an id (state, district, block, customer) or a six-digit PIN code
     *
     * @throws ValidationException
     */
    public function addCoverage(Territory $territory, CoverageKind $kind, string|int $reference, User $actor): TerritoryArea
    {
        $value = $this->resolveReference($kind, $reference);

        try {
            $area = DB::transaction(function () use ($territory, $kind, $value, $actor) {
                // the territory is locked so that "is it still active" and the insert agree
                $locked = Territory::query()->lockForUpdate()->findOrFail($territory->getKey());

                if ($locked->status !== 'active') {
                    throw ValidationException::withMessages(['coverage' => 'Switch the territory on before giving it coverage.']);
                }

                $area = new TerritoryArea;
                $area->forceFill([
                    'company_id' => (int) $locked->company_id, 'territory_id' => $locked->getKey(), 'kind' => $kind->value,
                    $kind->column() => $value, 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
                ])->save();

                $this->audit($locked, $actor, 'coverage_added', ['kind' => $kind->value, 'ref' => (string) $value]);

                return $area;
            });
        } catch (UniqueConstraintViolationException) {
            $holder = $this->holder($kind, $value);

            throw ValidationException::withMessages(['coverage' => $holder === null
                ? 'That is already covered by a territory.'
                : "That is already covered by {$holder->name} ({$holder->code}). Take it off that territory first."]);
        }

        return $area->refresh();
    }

    /** Take a unit of the map off its territory. */
    public function removeCoverage(TerritoryArea $area, User $actor): void
    {
        DB::transaction(function () use ($area, $actor) {
            $territory = Territory::query()->lockForUpdate()->findOrFail($area->territory_id);
            $found = TerritoryArea::query()->whereKey($area->getKey())->where('territory_id', $territory->getKey())->first();

            if ($found === null) {
                return;   // taken off a moment ago by somebody else: nothing to do
            }

            $found->forceDelete();
            $this->audit($territory, $actor, 'coverage_removed', ['kind' => $found->kind->value, 'ref' => (string) $found->ref]);
        });
    }

    /**
     * Remove a territory that covers nothing. One with coverage is switched off instead (its coverage is released first, on purpose).
     *
     * @throws ValidationException
     */
    public function delete(Territory $territory, User $actor): void
    {
        DB::transaction(function () use ($territory, $actor) {
            $locked = Territory::query()->lockForUpdate()->findOrFail($territory->getKey());

            if (TerritoryArea::query()->where('territory_id', $locked->getKey())->exists()) {
                throw ValidationException::withMessages(['territory' => 'Take its coverage off first, or switch the territory off instead.']);
            }

            $this->audit($locked, $actor, 'deleted', []);
            $locked->delete();
        });
    }

    /** @throws ValidationException */
    private function resolveReference(CoverageKind $kind, string|int $reference): string|int
    {
        if ($kind === CoverageKind::PinCode) {
            $pin = trim((string) $reference);

            return $this->pins->resolve($pin)->found ? $pin : $this->refuse('That PIN code is not in your geography master.');
        }

        $id = filter_var($reference, FILTER_VALIDATE_INT);

        if ($id === false || $id < 1) {
            $this->refuse("Choose a {$kind->label()}.");
        }

        $found = match ($kind) {
            CoverageKind::State => State::query()->where('status', 'active')->whereKey($id)->exists(),
            CoverageKind::District => District::query()->where('status', 'active')->whereKey($id)->exists(),
            CoverageKind::Block => Block::query()->where('status', 'active')->whereKey($id)->exists(),
            // the PIN code returned above, so what is left is a customer
            default => $this->ledgers->usable($id, LedgerType::Customer) !== null,
        };

        return $found ? $id : $this->refuse("Choose an active {$kind->label()} of this company.");
    }

    /** @throws ValidationException always */
    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['coverage' => $message]);
    }

    /** The territory that already covers this unit (for the message only). */
    private function holder(CoverageKind $kind, string|int $value): ?Territory
    {
        $area = TerritoryArea::query()->where('kind', $kind->value)->where($kind->column(), $value)->first();

        return $area === null ? null : Territory::query()->find($area->territory_id);
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(Territory $territory, User $actor, string $event, array $properties): void
    {
        activity('territories')->causedBy($actor)->performedOn($territory)->event($event)->withProperties($properties)->log($event);
    }

    private function blank(mixed $value): mixed
    {
        return $value === null || (is_string($value) && trim($value) === '') ? null : (is_string($value) ? trim($value) : $value);
    }
}
