<?php

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\CRM\Enums\RoutePlanStatus;
use App\Modules\CRM\Enums\StopType;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\AttendanceRecord;
use App\Modules\CRM\Models\GeofenceViolation;
use App\Modules\CRM\Models\RoutePlan;
use App\Modules\CRM\Models\RouteStop;
use App\Modules\CRM\Support\GeoMath;
use App\Modules\Masters\Enums\LedgerType;
use App\Modules\Masters\Models\Block;
use App\Modules\Masters\Services\LedgerLookup;
use App\Modules\Masters\Services\PinCodeLookup;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Rules\IndianPinCode;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Plans a salesperson's day. The single gate for route-plan data; it does not decide who may (RoutePlanPolicy does) but it
 * enforces the rules of the data:
 *
 *  - a plan is for **one person, one day and one branch**; one plan per person and day, ever (a cancelled plan is
 *    reinstated, not replaced). The planner must manage that person (their team, or everyone with `sales.scope_all`),
 *    and the person must be able to work in that branch;
 *  - a plan is created for today or a later day, and a day that is over is history: it is not edited, cancelled or deleted;
 *  - stops (at most 50) are an area, a block, a PIN code or a customer, in order, each optionally with a place on the
 *    map (both coordinates, a real position), its own radius (50–5000 m) and a visit window; a block, PIN code or customer
 *    must exist in the company's masters, and a customer must be an approved, active customer ledger;
 *  - **publishing** needs at least one stop with a place on the map — otherwise there would be no geofence to check
 *    attendance against — and only a published plan lets the person mark attendance;
 *  - a stop that has been used (it matched an attendance, or a refused attempt was measured against it) is kept.
 *
 * Input: ['plan' => [user_id, location_id, plan_date, title, geofence_radius_m, remarks],
 *         'stops' => [[id?, stop_type, label, block_id, pin_code, ledger_id, latitude, longitude, radius_m, visit_from, visit_to], …]]
 */
final class RoutePlanService
{
    public const MAX_STOPS = 50;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SalesScope $scope,
        private readonly SalesTeamService $teams,
        private readonly LedgerLookup $ledgers,
        private readonly PinCodeLookup $pins,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function save(?RoutePlan $plan, array $input, User $actor, ?string $today = null): RoutePlan
    {
        $today ??= now()->toDateString();

        if ($plan !== null) {
            $this->assertEditable($plan, $today);
        }

        $data = $this->validate($input, $plan, $actor, $today);

        return DB::transaction(function () use ($data, $plan, $actor) {
            $model = $plan ?? new RoutePlan;
            $model->fill(['title' => $data['plan']['title'], 'geofence_radius_m' => $data['plan']['geofence_radius_m'], 'remarks' => $data['plan']['remarks']]);
            $model->forceFill([
                'user_id' => $data['plan']['user_id'], 'location_id' => $data['plan']['location_id'],
                'plan_date' => $data['plan']['plan_date'], 'updated_by' => $actor->getKey(),
            ]);

            if ($plan === null) {
                $model->forceFill(['company_id' => (int) $this->tenant->activeCompanyId(), 'created_by' => $actor->getKey(), 'status' => RoutePlanStatus::Draft->value]);
            }

            try {
                $model->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['plan.plan_date' => 'That person already has a plan for that day. Open it instead.']);
            }

            $this->syncStops($model, $data['stops'], $actor);

            return $model->refresh();
        });
    }

    /** Make the plan live: from now on the person can mark attendance against it. */
    public function publish(RoutePlan $plan, User $actor, ?string $today = null): RoutePlan
    {
        $today ??= now()->toDateString();

        return $this->transition($plan, $actor, RoutePlanStatus::Published, null, function (RoutePlan $fresh) use ($today) {
            if ($fresh->status !== RoutePlanStatus::Draft) {
                throw new FieldRuleException('Only a draft plan can be published.');
            }

            if ($fresh->isPast($today)) {
                throw new FieldRuleException('That day is over, so the plan cannot be published.');
            }

            if (! $fresh->stops()->whereNotNull('latitude')->exists()) {
                throw new FieldRuleException('Give at least one stop a place on the map before publishing: attendance is checked against it.');
            }

            if (! array_key_exists((int) $fresh->user_id, $this->teams->staff())) {
                throw new FieldRuleException('That person is no longer an active user of this company.');
            }
        });
    }

    /** Call the plan off. Refused once attendance has been marked against it. */
    public function cancel(RoutePlan $plan, string $reason, User $actor, ?string $today = null): RoutePlan
    {
        $today ??= now()->toDateString();
        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Please say why the plan is being cancelled.']);
        }

        return $this->transition($plan, $actor, RoutePlanStatus::Cancelled, $reason, function (RoutePlan $fresh) use ($today) {
            if ($fresh->status === RoutePlanStatus::Cancelled) {
                throw new FieldRuleException('This plan is already cancelled.');
            }

            if ($fresh->isPast($today)) {
                throw new FieldRuleException('That day is over, so the plan is history and cannot be cancelled.');
            }

            if (AttendanceRecord::query()->where('route_plan_id', $fresh->getKey())->exists()) {
                throw new FieldRuleException('Attendance has already been marked against this plan, so it cannot be cancelled.');
            }
        });
    }

    /** Take a cancelled plan back to a draft so it can be corrected and published again. */
    public function reinstate(RoutePlan $plan, User $actor, ?string $today = null): RoutePlan
    {
        $today ??= now()->toDateString();

        return $this->transition($plan, $actor, RoutePlanStatus::Draft, null, function (RoutePlan $fresh) use ($today) {
            if ($fresh->status !== RoutePlanStatus::Cancelled) {
                throw new FieldRuleException('Only a cancelled plan can be reinstated.');
            }

            if ($fresh->isPast($today)) {
                throw new FieldRuleException('That day is over, so the plan cannot be reinstated.');
            }
        });
    }

    /**
     * Remove a draft that has never been used. Its day then becomes free for a new plan.
     *
     * @throws FieldRuleException
     */
    public function delete(RoutePlan $plan): void
    {
        DB::transaction(function () use ($plan) {
            $fresh = RoutePlan::query()->lockForUpdate()->findOrFail($plan->getKey());

            if ($fresh->status !== RoutePlanStatus::Draft) {
                throw new FieldRuleException('Only a draft plan can be deleted. Cancel a published plan instead.');
            }

            if (AttendanceRecord::query()->where('route_plan_id', $fresh->getKey())->exists() || GeofenceViolation::query()->where('route_plan_id', $fresh->getKey())->exists()) {
                throw new FieldRuleException('This plan appears in the attendance records or the violation log, so it cannot be deleted.');
            }

            // Stops and the plan go for good (their unique keys must be reusable); the audit log keeps what they held.
            $fresh->stops()->get()->each(fn (RouteStop $stop) => $stop->forceDelete());
            $fresh->forceDelete();
        });
    }

    // ---- validation -------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     * @return array{plan: array<string, mixed>, stops: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public function validate(array $input, ?RoutePlan $editing, User $actor, string $today): array
    {
        $data = $this->normalise($input);
        $radius = (array) config('formezy.settings.geofence.radius_m');
        $minRadius = (int) ($radius['min'] ?? 50);
        $maxRadius = (int) ($radius['max'] ?? 5000);

        $rules = [
            'plan.user_id' => ['required', 'integer'],
            'plan.location_id' => ['required', 'integer'],
            'plan.plan_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'plan.title' => ['nullable', 'string', 'max:150'],
            'plan.geofence_radius_m' => ['nullable', 'integer', "between:{$minRadius},{$maxRadius}"],
            'plan.remarks' => ['nullable', 'string', 'max:1000'],
            'stops' => ['array', 'max:'.self::MAX_STOPS],
        ];

        foreach ($data['stops'] as $i => $stop) {
            $rules["stops.$i.id"] = ['nullable', 'integer'];
            $rules["stops.$i.stop_type"] = ['required', Rule::in(StopType::values())];
            $rules["stops.$i.label"] = ['nullable', 'string', 'max:150'];
            $rules["stops.$i.block_id"] = ['nullable', 'integer'];
            $rules["stops.$i.pin_code"] = ['nullable', 'string', 'max:6'];
            $rules["stops.$i.ledger_id"] = ['nullable', 'integer'];
            $rules["stops.$i.latitude"] = ['nullable', 'numeric'];
            $rules["stops.$i.longitude"] = ['nullable', 'numeric'];
            $rules["stops.$i.radius_m"] = ['nullable', 'integer', "between:{$minRadius},{$maxRadius}"];
            $rules["stops.$i.visit_from"] = ['nullable', 'date_format:H:i'];
            $rules["stops.$i.visit_to"] = ['nullable', 'date_format:H:i'];
        }

        $validator = Validator::make($data, $rules, [
            'plan.plan_date.date_format' => 'Choose the day of the plan.',
            'plan.geofence_radius_m.between' => "The radius must be between {$minRadius} and {$maxRadius} metres.",
            'stops.max' => 'A plan can have at most '.self::MAX_STOPS.' stops.',
            'stops.*.radius_m.between' => "A stop's radius must be between {$minRadius} and {$maxRadius} metres.",
            'stops.*.visit_from.date_format' => 'Enter the time as HH:MM.',
            'stops.*.visit_to.date_format' => 'Enter the time as HH:MM.',
        ], ['plan.user_id' => 'salesperson', 'plan.location_id' => 'branch', 'plan.plan_date' => 'day']);

        $validator->after(function (\Illuminate\Validation\Validator $v) use (&$data, $editing, $actor, $today) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $this->checkPlan($v, $data['plan'], $editing, $actor, $today);
            $data['stops'] = $this->checkStops($v, $data['stops'], $editing);

            if ($editing?->isPublished() && $v->errors()->isEmpty() && collect($data['stops'])->doesntContain(fn (array $s) => $s['latitude'] !== null)) {
                $v->errors()->add('stops', 'A published plan needs at least one stop with a place on the map: attendance is checked against it.');
            }
        });

        $validator->validate();

        return $data;
    }

    /** @param  array<string, mixed>  $plan */
    private function checkPlan(\Illuminate\Validation\Validator $v, array $plan, ?RoutePlan $editing, User $actor, string $today): void
    {
        $userId = (int) $plan['user_id'];
        $staff = $this->teams->staff();

        if (! array_key_exists($userId, $staff)) {
            $v->errors()->add('plan.user_id', 'Choose an active user of this company.');
        } elseif (! $this->scope->manages($actor, $userId)) {
            $v->errors()->add('plan.user_id', 'You can plan the days only of the people on the teams you manage.');
        }

        $branch = Location::query()->find((int) $plan['location_id']);

        if ($branch === null || ($branch->status !== 'active' && (int) $branch->getKey() !== (int) $editing?->location_id)) {
            $v->errors()->add('plan.location_id', 'Choose one of your active branches.');
        } elseif (array_key_exists($userId, $staff)) {
            $their = $this->tenant->accessibleLocationIdsFor(User::query()->findOrFail($userId), (int) $this->tenant->activeCompanyId());

            if ($their !== null && ! in_array((int) $branch->getKey(), $their, true)) {
                $v->errors()->add('plan.location_id', "{$staff[$userId]} does not work in that branch.");
            }
        }

        if ($plan['plan_date'] < $today && ($editing === null || $plan['plan_date'] !== $editing->plan_date->toDateString())) {
            $v->errors()->add('plan.plan_date', 'A plan is made for today or a later day.');
        }

        if ($editing !== null && AttendanceRecord::query()->where('route_plan_id', $editing->getKey())->exists()) {
            foreach (['user_id' => 'person', 'plan_date' => 'day', 'location_id' => 'branch'] as $field => $label) {
                $now = $field === 'plan_date' ? $plan[$field] : (int) $plan[$field];
                $was = $field === 'plan_date' ? $editing->plan_date->toDateString() : (int) $editing->getAttribute($field);

                if ($now !== $was) {
                    $v->errors()->add("plan.$field", "The {$label} of a plan that attendance has been marked against cannot be changed.");
                }
            }
        }

        if ($v->errors()->isEmpty()) {
            $clash = RoutePlan::query()->where('user_id', $userId)->where('plan_date', $plan['plan_date'])
                ->when($editing !== null, fn ($q) => $q->whereKeyNot($editing?->getKey()))->first();

            if ($clash !== null) {
                $v->errors()->add('plan.plan_date', "{$staff[$userId]} already has a plan for ".Carbon::parse($plan['plan_date'])->format('j M Y').' ('.strtolower($clash->status->label()).'). Open it instead.');
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $stops
     * @return list<array<string, mixed>> the stops, completed (labels filled from the masters, coordinates normalised)
     */
    private function checkStops(\Illuminate\Validation\Validator $v, array $stops, ?RoutePlan $editing): array
    {
        $existing = $editing === null ? collect() : $editing->stops()->get()->keyBy('id');
        $seenIds = [];

        foreach ($stops as $i => $stop) {
            $type = StopType::from($stop['stop_type']);

            if ($stop['id'] !== null) {
                if (! $existing->has((int) $stop['id']) || isset($seenIds[(int) $stop['id']])) {
                    $v->errors()->add("stops.$i.id", 'This row does not belong to the plan.');
                }
                $seenIds[(int) $stop['id']] = true;
            }

            $stops[$i] = $this->checkReference($v, $i, $type, $stop);
            $stop = $stops[$i];

            if (($stop['latitude'] === null) !== ($stop['longitude'] === null)) {
                $v->errors()->add("stops.$i.latitude", 'Give both the latitude and the longitude, or neither.');
            } elseif ($stop['latitude'] !== null) {
                if (! GeoMath::isPosition((float) $stop['latitude'], (float) $stop['longitude'])) {
                    $v->errors()->add("stops.$i.latitude", 'That is not a position on the map: latitude must be −90 to 90 and longitude −180 to 180.');
                } else {
                    $stops[$i]['latitude'] = number_format((float) $stop['latitude'], 7, '.', '');
                    $stops[$i]['longitude'] = number_format((float) $stop['longitude'], 7, '.', '');
                }
            }

            if ($stop['visit_from'] !== null && $stop['visit_to'] !== null && $stop['visit_to'] <= $stop['visit_from']) {
                $v->errors()->add("stops.$i.visit_to", 'The visit must end after it starts.');
            }

            if (trim((string) $stops[$i]['label']) === '') {
                $v->errors()->add("stops.$i.label", 'Name the stop.');
            }
        }

        // A stop that attendance or a refused attempt was measured against is part of the record: keep it.
        foreach ($existing as $id => $stop) {
            if (! isset($seenIds[$id]) && $this->isReferenced($stop)) {
                $v->errors()->add('stops', "The stop “{$stop->label}” was used when attendance was accepted or refused, so it cannot be removed.");
            }
        }

        return $stops;
    }

    /**
     * @param  array<string, mixed>  $stop
     * @return array<string, mixed>
     */
    private function checkReference(\Illuminate\Validation\Validator $v, int $i, StopType $type, array $stop): array
    {
        switch ($type) {
            case StopType::Block:
                $block = $stop['block_id'] === null ? null : Block::query()->with('district:id,name')->find((int) $stop['block_id']);

                if ($block === null) {
                    $v->errors()->add("stops.$i.block_id", 'Choose a block.');
                } elseif ($stop['label'] === null) {
                    $stop['label'] = $block->name.($block->district ? ', '.$block->district->name : '');
                }

                $stop['pin_code'] = null;
                $stop['ledger_id'] = null;
                break;

            case StopType::PinCode:
                $pin = (string) $stop['pin_code'];

                if (! IndianPinCode::isValid($pin)) {
                    $v->errors()->add("stops.$i.pin_code", 'Enter a 6-digit PIN code.');
                } elseif (! $this->pins->resolve($pin)->found) {
                    $v->errors()->add("stops.$i.pin_code", 'That PIN code is not in the geography master.');
                } elseif ($stop['label'] === null) {
                    $stop['label'] = "PIN {$pin}";
                }

                $stop['block_id'] = null;
                $stop['ledger_id'] = null;
                break;

            case StopType::Customer:
                $customer = $stop['ledger_id'] === null ? null : $this->ledgers->usable((int) $stop['ledger_id'], LedgerType::Customer);

                if ($customer === null) {
                    $v->errors()->add("stops.$i.ledger_id", 'Choose an approved, active customer.');
                } elseif ($stop['label'] === null) {
                    $stop['label'] = $customer->name;
                }

                $stop['block_id'] = null;
                $stop['pin_code'] = null;
                break;

            case StopType::Area:
                $stop['block_id'] = null;
                $stop['pin_code'] = null;
                $stop['ledger_id'] = null;
                break;
        }

        return $stop;
    }

    private function isReferenced(RouteStop $stop): bool
    {
        return AttendanceRecord::query()->where('route_stop_id', $stop->getKey())->exists()
            || GeofenceViolation::query()->where('route_stop_id', $stop->getKey())->exists();
    }

    // ---- persistence ------------------------------------------------------------------------------------------

    /** @param  list<array<string, mixed>>  $stops */
    private function syncStops(RoutePlan $plan, array $stops, User $actor): void
    {
        $existing = $plan->stops()->get()->keyBy('id');

        // Move the current stops out of the way first: the (plan, sequence) key must never be held twice, even briefly.
        if ($existing->isNotEmpty()) {
            RouteStop::query()->where('route_plan_id', $plan->getKey())->update(['sequence' => DB::raw('sequence + 1000')]);
        }

        $kept = [];

        foreach ($stops as $index => $row) {
            $stop = $row['id'] !== null ? $existing->get((int) $row['id']) : null;

            $model = $stop ?? new RouteStop;
            $model->fill([
                'sequence' => $index + 1, 'stop_type' => $row['stop_type'], 'label' => $row['label'], 'pin_code' => $row['pin_code'],
                'latitude' => $row['latitude'], 'longitude' => $row['longitude'], 'radius_m' => $row['radius_m'],
                'visit_from' => $row['visit_from'], 'visit_to' => $row['visit_to'], 'remarks' => $row['remarks'],
            ]);
            $model->forceFill([
                'block_id' => $row['block_id'], 'ledger_id' => $row['ledger_id'], 'updated_by' => $actor->getKey(),
            ]);

            if ($stop === null) {
                $model->forceFill(['company_id' => (int) $plan->company_id, 'route_plan_id' => $plan->getKey(), 'created_by' => $actor->getKey(), 'visit_status' => 'pending']);
            }

            $model->save();
            $kept[] = $model->getKey();
        }

        foreach ($existing as $id => $stop) {
            if (! in_array($id, $kept, true)) {
                $stop->forceDelete();
            }
        }
    }

    private function assertEditable(RoutePlan $plan, string $today): void
    {
        if ($plan->status === RoutePlanStatus::Cancelled) {
            throw new FieldRuleException('This plan is cancelled. Reinstate it to change it.');
        }

        if ($plan->isPast($today)) {
            throw new FieldRuleException('That day is over, so the plan is history and cannot be changed.');
        }
    }

    /**
     * @param  callable(RoutePlan): void  $guard  throws FieldRuleException when the move is not allowed
     */
    private function transition(RoutePlan $plan, User $actor, RoutePlanStatus $to, ?string $comment, callable $guard): RoutePlan
    {
        return DB::transaction(function () use ($plan, $actor, $to, $comment, $guard) {
            $fresh = RoutePlan::query()->lockForUpdate()->findOrFail($plan->getKey());
            $guard($fresh);

            $from = $fresh->status;
            $fresh->forceFill(['status' => $to->value, 'updated_by' => $actor->getKey()])->save();

            $event = match ($to) {
                RoutePlanStatus::Published => 'published',
                RoutePlanStatus::Cancelled => 'cancelled',
                RoutePlanStatus::Draft => 'reinstated',
            };

            activity('route_plans')
                ->causedBy($actor)->performedOn($fresh)->event($event)
                ->withProperties(['old' => ['status' => $from->value], 'attributes' => ['status' => $to->value], 'comment' => $comment])
                ->log($event);

            return $fresh->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{plan: array<string, mixed>, stops: list<array<string, mixed>>}
     */
    private function normalise(array $input): array
    {
        $text = fn (mixed $v): ?string => ($v = trim((string) preg_replace('/\s+/u', ' ', (string) ($v ?? '')))) === '' ? null : $v;
        $plan = (array) ($input['plan'] ?? []);

        $stops = [];
        foreach (array_values((array) ($input['stops'] ?? [])) as $row) {
            $row = (array) $row;
            $stops[] = [
                'id' => $text($row['id'] ?? null),
                'stop_type' => $text($row['stop_type'] ?? null) ?? StopType::Area->value,
                'label' => $text($row['label'] ?? null),
                'block_id' => $text($row['block_id'] ?? null),
                'pin_code' => $text($row['pin_code'] ?? null),
                'ledger_id' => $text($row['ledger_id'] ?? null),
                'latitude' => $text($row['latitude'] ?? null),
                'longitude' => $text($row['longitude'] ?? null),
                'radius_m' => $text($row['radius_m'] ?? null),
                'visit_from' => $text($row['visit_from'] ?? null),
                'visit_to' => $text($row['visit_to'] ?? null),
                'remarks' => $text($row['remarks'] ?? null),
            ];
        }

        return [
            'plan' => [
                'user_id' => $text($plan['user_id'] ?? null),
                'location_id' => $text($plan['location_id'] ?? null),
                'plan_date' => $text($plan['plan_date'] ?? null),
                'title' => $text($plan['title'] ?? null),
                'geofence_radius_m' => $text($plan['geofence_radius_m'] ?? null),
                'remarks' => ($remarks = trim((string) ($plan['remarks'] ?? ''))) === '' ? null : $remarks,
            ],
            'stops' => $stops,
        ];
    }
}
