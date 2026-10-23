<?php

use App\Modules\CRM\Enums\RoutePlanStatus;
use App\Modules\CRM\Exceptions\AttendanceRefused;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\GeofenceViolation;
use App\Modules\CRM\Models\RoutePlan;
use App\Modules\CRM\Models\RouteStop;
use App\Modules\CRM\Services\AttendanceService;
use App\Modules\CRM\Services\RoutePlanService;
use App\Modules\Masters\Models\Block;
use App\Modules\Shared\Models\ActivityLog;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->geo = seedGeography($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');
    $this->globexBranch = makeLocation($this->globex, 'GLB');

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->otherManager = makeUser(['Sales Manager'], [$this->acme]);
    $this->admin = makeUser(['Company Admin'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde]);
    $this->plans = app(RoutePlanService::class);

    $this->save = fn (array $plan = [], ?array $stops = null, $actor = null, $existing = null) => inCompany(
        $this->acme,
        fn () => $this->plans->save($existing, planInput($this->bde, $this->ahd, $plan, $stops), $actor ?? $this->manager),
    );
    $this->complaints = function (array $plan = [], ?array $stops = null, $actor = null, $existing = null) {
        try {
            ($this->save)($plan, $stops, $actor, $existing);
        } catch (ValidationException $e) {
            return array_keys($e->errors());
        }

        return [];
    };
});

afterEach(fn () => Carbon::setTestNow());

// ======================================================================================================
// Planning a day
// ======================================================================================================

it('plans a day as a draft, with its stops in order', function () {
    $plan = ($this->save)(['title' => '  Naroda   round '], [
        stopInput(['label' => 'First']), stopInput(['label' => 'Second', 'latitude' => '23.05', 'longitude' => '72.6', 'radius_m' => '250', 'visit_from' => '10:00', 'visit_to' => '11:30']),
    ]);

    expect($plan->status)->toBe(RoutePlanStatus::Draft)->and($plan->user_id)->toBe($this->bde->id)->and($plan->location_id)->toBe($this->ahd->id)
        ->and($plan->plan_date->toDateString())->toBe('2026-10-12')->and($plan->title)->toBe('Naroda round')->and($plan->company_id)->toBe($this->acme->id)
        ->and($plan->created_by)->toBe($this->manager->id);

    $stops = $plan->stops;
    expect($stops->pluck('sequence')->all())->toBe([1, 2])->and($stops->pluck('label')->all())->toBe(['First', 'Second'])
        ->and($stops[1]->latitude)->toBe('23.0500000')->and($stops[1]->longitude)->toBe('72.6000000')->and($stops[1]->radius_m)->toBe(250)
        ->and($stops[1]->visit_from)->toBe('10:00:00')->and($stops[1]->visit_to)->toBe('11:30:00')->and($stops[0]->visit_status)->toBe('pending');
});

it('lets a manager plan only their own people, and never themselves; an administrator anyone', function () {
    expect(($this->complaints)(['user_id' => (string) $this->bde2->id]))->toBe(['plan.user_id'])
        ->and(($this->complaints)(['user_id' => (string) $this->manager->id]))->toBe(['plan.user_id'])
        ->and(($this->complaints)([], null, $this->otherManager))->toBe(['plan.user_id']);

    expect(($this->save)(['user_id' => (string) $this->bde2->id], null, $this->admin)->user_id)->toBe($this->bde2->id);
});

it('refuses an inactive person, a stranger and an unknown one', function () {
    $inactive = makeUser(['BDE / Field Salesperson'], [$this->acme], null, ['is_active' => false]);
    $stranger = makeUser(['BDE / Field Salesperson'], [$this->globex]);

    foreach ([$inactive->id, $stranger->id, 999999] as $id) {
        expect(($this->complaints)(['user_id' => (string) $id], null, $this->admin))->toBe(['plan.user_id']);
    }
});

it('plans a person only at a branch they work in, and the planner can reach, and that is the company’s', function () {
    expect(($this->complaints)(['location_id' => (string) $this->mum->id]))->toBe(['plan.location_id'])           // the BDE works only in AHD
        ->and(($this->complaints)(['location_id' => (string) $this->globexBranch->id]))->toBe(['plan.location_id'])
        ->and(($this->complaints)(['location_id' => '']))->toBe(['plan.location_id']);

    $this->ahd->forceFill(['status' => 'inactive'])->save();
    expect(($this->complaints)([]))->toBe(['plan.location_id']);
});

it('plans today or a later day, one plan per person per day', function () {
    expect(($this->complaints)(['plan_date' => '2026-10-11']))->toBe(['plan.plan_date'])
        ->and(($this->complaints)(['plan_date' => 'soon']))->toBe(['plan.plan_date'])
        ->and(($this->complaints)(['plan_date' => '2026-02-30']))->toBe(['plan.plan_date']);

    ($this->save)(['plan_date' => '2026-10-14']);

    try {
        ($this->save)(['plan_date' => '2026-10-14']);
        $this->fail('a second plan for the same day was accepted');
    } catch (ValidationException $e) {
        expect($e->errors()['plan.plan_date'][0])->toContain('already has a plan for 14 Oct 2026')->toContain('draft');
    }

    expect(RoutePlan::query()->count())->toBe(1);
});

it('keeps the stops to fifty', function () {
    $fifty = array_map(fn ($i) => stopInput(['label' => "Stop {$i}"]), range(1, 50));

    expect(($this->complaints)([], $fifty))->toBe([])
        ->and(($this->complaints)(['plan_date' => '2026-10-15'], [...$fifty, stopInput(['label' => 'One too many'])]))->toBe(['stops']);
});

it('checks each stop’s place on the map, radius and visit window', function () {
    $bad = [
        'latitude without longitude' => [stopInput(['longitude' => '']), 'stops.0.latitude'],
        'longitude without latitude' => [stopInput(['latitude' => '']), 'stops.0.latitude'],
        'latitude out of range' => [stopInput(['latitude' => '91']), 'stops.0.latitude'],
        'longitude out of range' => [stopInput(['longitude' => '181']), 'stops.0.latitude'],
        'null island' => [stopInput(['latitude' => '0', 'longitude' => '0']), 'stops.0.latitude'],
        'not a number' => [stopInput(['latitude' => 'north']), 'stops.0.latitude'],
        'radius too small' => [stopInput(['radius_m' => '10']), 'stops.0.radius_m'],
        'radius too large' => [stopInput(['radius_m' => '9000']), 'stops.0.radius_m'],
        'visit ends before it starts' => [stopInput(['visit_from' => '11:00', 'visit_to' => '10:00']), 'stops.0.visit_to'],
        'visit window not a time' => [stopInput(['visit_from' => '25:99']), 'stops.0.visit_from'],
        'no name' => [stopInput(['label' => '']), 'stops.0.label'],
        'unknown kind' => [stopInput(['stop_type' => 'planet']), 'stops.0.stop_type'],
    ];

    foreach ($bad as $what => [$stop, $field]) {
        expect(in_array($field, ($this->complaints)([], [$stop]), true))->toBeTrue("{$what} should be refused");
    }

    // a stop without coordinates is fine: it is a reminder
    expect(($this->complaints)([], [stopInput(['latitude' => '', 'longitude' => ''])]))->toBe([]);
});

it('checks the plan-wide radius', function () {
    expect(($this->complaints)(['geofence_radius_m' => '49']))->toBe(['plan.geofence_radius_m'])
        ->and(($this->complaints)(['geofence_radius_m' => '5001']))->toBe(['plan.geofence_radius_m'])
        ->and(($this->complaints)(['geofence_radius_m' => '750']))->toBe([]);
});

// ======================================================================================================
// Stops that refer to the masters
// ======================================================================================================

it('names a block, a PIN code or a customer stop from the master it refers to', function () {
    $customer = makeLedger($this->acme, makeUser(['Accounts User'], [$this->acme]), 'customer', [], true);

    $plan = ($this->save)([], [
        stopInput(['stop_type' => 'block', 'label' => '', 'block_id' => (string) $this->geo['daskroi']->id]),
        stopInput(['stop_type' => 'pin_code', 'label' => '', 'pin_code' => '380001']),
        stopInput(['stop_type' => 'customer', 'label' => '', 'ledger_id' => (string) $customer->id]),
    ]);

    expect($plan->stops->pluck('label')->all())->toBe(['Daskroi, Ahmedabad', 'PIN 380001', $customer->name])
        ->and($plan->stops[0]->block_id)->toBe($this->geo['daskroi']->id)->and($plan->stops[1]->pin_code)->toBe('380001')->and($plan->stops[2]->ledger_id)->toBe($customer->id);
});

it('forgets what a stop used to refer to when its kind changes, so a stale id can never ride along', function () {
    $plan = ($this->save)([], [stopInput(['stop_type' => 'block', 'block_id' => (string) $this->geo['daskroi']->id])]);

    // the same row, now an area, still carrying the old block id in the payload
    $edited = ($this->save)([], [stopInput(['id' => (string) $plan->stops[0]->id, 'stop_type' => 'area', 'block_id' => (string) $this->geo['daskroi']->id, 'pin_code' => '380001', 'ledger_id' => '1'])], null, $plan);

    expect($edited->stops[0]->block_id)->toBeNull()->and($edited->stops[0]->pin_code)->toBeNull()->and($edited->stops[0]->ledger_id)->toBeNull();
});

it('refuses a reference that is missing, another company’s, or not usable', function () {
    $accounts = makeUser(['Accounts User'], [$this->acme]);
    $draftCustomer = makeLedger($this->acme, $accounts, 'customer');                                        // never approved
    $supplier = makeLedger($this->acme, $accounts, 'supplier', [], true);                                   // approved, but not a customer
    $foreignBlock = inCompany($this->globex, fn () => (new Block(['district_id' => seedGeography($this->globex)['ahmedabad']->id, 'name' => 'Theirs']))->forceFill(['company_id' => $this->globex->id]))->id ?? null;

    $cases = [
        'no block' => [stopInput(['stop_type' => 'block']), 'stops.0.block_id'],
        'unknown block' => [stopInput(['stop_type' => 'block', 'block_id' => '999999']), 'stops.0.block_id'],
        'PIN not six digits' => [stopInput(['stop_type' => 'pin_code', 'pin_code' => '38000']), 'stops.0.pin_code'],
        'PIN not in the master' => [stopInput(['stop_type' => 'pin_code', 'pin_code' => '560001']), 'stops.0.pin_code'],
        'no customer' => [stopInput(['stop_type' => 'customer']), 'stops.0.ledger_id'],
        'customer not approved' => [stopInput(['stop_type' => 'customer', 'ledger_id' => (string) $draftCustomer->id]), 'stops.0.ledger_id'],
        'a supplier, not a customer' => [stopInput(['stop_type' => 'customer', 'ledger_id' => (string) $supplier->id]), 'stops.0.ledger_id'],
    ];

    foreach ($cases as $what => [$stop, $field]) {
        expect(in_array($field, ($this->complaints)([], [$stop]), true))->toBeTrue("{$what} should be refused");
    }
});

// ======================================================================================================
// Publishing, cancelling, reinstating, deleting
// ======================================================================================================

it('publishes a plan that has a stop on the map, and records who did it', function () {
    $draft = makePlan($this->acme, $this->manager, $this->bde, $this->ahd, [], null, 'draft');

    $published = inCompany($this->acme, fn () => $this->plans->publish($draft, $this->manager));

    expect($published->status)->toBe(RoutePlanStatus::Published);

    $audit = ActivityLog::query()->where('log_name', 'route_plans')->where('event', 'published')->sole();
    expect((int) $audit->causer_id)->toBe($this->manager->id)->and($audit->properties['old'])->toBe(['status' => 'draft'])->and($audit->properties['attributes'])->toBe(['status' => 'published']);
});

it('will not publish a plan with no stop on the map, a plan that is not a draft, or a day that is over', function () {
    $reminders = makePlan($this->acme, $this->manager, $this->bde, $this->ahd, [], [stopInput(['latitude' => '', 'longitude' => ''])], 'draft');
    expect(fn () => inCompany($this->acme, fn () => $this->plans->publish($reminders, $this->manager)))->toThrow(FieldRuleException::class, 'place on the map');

    $live = makePlan($this->acme, $this->manager, $this->bde, $this->ahd, ['plan_date' => '2026-10-14']);
    expect(fn () => inCompany($this->acme, fn () => $this->plans->publish($live, $this->manager)))->toThrow(FieldRuleException::class, 'Only a draft');

    $tomorrow = makePlan($this->acme, $this->manager, $this->bde, $this->ahd, ['plan_date' => '2026-10-13'], null, 'draft');
    Carbon::setTestNow('2026-10-14 08:00:00');
    expect(fn () => inCompany($this->acme, fn () => $this->plans->publish($tomorrow, $this->manager)))->toThrow(FieldRuleException::class, 'day is over');
});

it('will not publish for somebody who is no longer an active user', function () {
    $draft = makePlan($this->acme, $this->manager, $this->bde, $this->ahd, [], null, 'draft');
    $this->bde->forceFill(['is_active' => false])->save();

    expect(fn () => inCompany($this->acme, fn () => $this->plans->publish($draft, $this->manager)))->toThrow(FieldRuleException::class, 'no longer an active user');
});

it('cancels a plan with a reason, and a cancelled plan can be reinstated as a draft', function () {
    $plan = makePlan($this->acme, $this->manager, $this->bde, $this->ahd);

    expect(fn () => inCompany($this->acme, fn () => $this->plans->cancel($plan, '', $this->manager)))->toThrow(ValidationException::class)
        ->and(fn () => inCompany($this->acme, fn () => $this->plans->cancel($plan, 'no', $this->manager)))->toThrow(ValidationException::class);

    $cancelled = inCompany($this->acme, fn () => $this->plans->cancel($plan->fresh(), 'The customer postponed the visit', $this->manager));
    $audit = ActivityLog::query()->where('event', 'cancelled')->sole();

    expect($cancelled->status)->toBe(RoutePlanStatus::Cancelled)->and($audit->properties['comment'])->toBe('The customer postponed the visit')
        ->and(fn () => inCompany($this->acme, fn () => $this->plans->cancel($cancelled->fresh(), 'Again', $this->manager)))->toThrow(FieldRuleException::class, 'already cancelled');

    $back = inCompany($this->acme, fn () => $this->plans->reinstate($cancelled->fresh(), $this->manager));
    expect($back->status)->toBe(RoutePlanStatus::Draft)
        ->and(fn () => inCompany($this->acme, fn () => $this->plans->reinstate($back->fresh(), $this->manager)))->toThrow(FieldRuleException::class, 'Only a cancelled');
});

it('cannot be cancelled once attendance has been marked against it, or once the day is over', function () {
    $plan = makePlan($this->acme, $this->manager, $this->bde, $this->ahd);
    inCompany($this->acme, fn () => app(AttendanceService::class)->mark($this->bde, HQ_LAT, HQ_LNG, 10));

    expect(fn () => inCompany($this->acme, fn () => $this->plans->cancel($plan->fresh(), 'Too late now', $this->manager)))->toThrow(FieldRuleException::class, 'Attendance has already been marked');

    Carbon::setTestNow('2026-10-13 08:00:00');
    $other = makePlan($this->acme, $this->manager, $this->bde, $this->ahd, ['plan_date' => '2026-10-13']);
    Carbon::setTestNow('2026-10-14 08:00:00');
    expect(fn () => inCompany($this->acme, fn () => $this->plans->cancel($other, 'Too late', $this->manager)))->toThrow(FieldRuleException::class, 'day is over');
});

it('deletes only a draft that was never used, and frees the day', function () {
    $draft = makePlan($this->acme, $this->manager, $this->bde, $this->ahd, [], null, 'draft');
    $published = makePlan($this->acme, $this->manager, $this->bde, $this->ahd, ['plan_date' => '2026-10-14']);

    expect(fn () => inCompany($this->acme, fn () => $this->plans->delete($published)))->toThrow(FieldRuleException::class, 'Only a draft');

    inCompany($this->acme, fn () => $this->plans->delete($draft));

    expect(RoutePlan::withTrashed()->whereKey($draft->id)->exists())->toBeFalse()->and(RouteStop::withTrashed()->where('route_plan_id', $draft->id)->exists())->toBeFalse()
        ->and(($this->complaints)([]))->toBe([]);      // today is free again
});

it('keeps a draft that the log refers to', function () {
    $draft = makePlan($this->acme, $this->manager, $this->bde, $this->ahd, [], null, 'draft');
    $draft->forceFill(['status' => 'published'])->save();
    try {
        inCompany($this->acme, fn () => app(AttendanceService::class)->mark($this->bde, 23.1, HQ_LNG, 10));     // refused: outside
    } catch (AttendanceRefused) {
        // that is the point: it is now in the log
    }
    $draft->forceFill(['status' => 'draft'])->save();

    expect(GeofenceViolation::query()->count())->toBe(1)
        ->and(fn () => inCompany($this->acme, fn () => $this->plans->delete($draft->fresh())))->toThrow(FieldRuleException::class, 'violation log');
});

// ======================================================================================================
// Changing a plan
// ======================================================================================================

it('edits stops in place, keeping their ids, and reorders them without tripping over the order key', function () {
    $plan = ($this->save)([], [stopInput(['label' => 'A']), stopInput(['label' => 'B']), stopInput(['label' => 'C'])]);
    [$a, $b, $c] = $plan->stops->all();

    $edited = ($this->save)([], [
        stopInput(['id' => (string) $c->id, 'label' => 'C']), stopInput(['id' => (string) a($a), 'label' => 'A renamed']), stopInput(['label' => 'D new']),
    ], null, $plan);

    expect($edited->stops->pluck('label')->all())->toBe(['C', 'A renamed', 'D new'])->and($edited->stops->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and($edited->stops[0]->id)->toBe($c->id)->and($edited->stops[1]->id)->toBe($a->id)
        ->and(RouteStop::withTrashed()->whereKey($b->id)->exists())->toBeFalse();     // B was dropped for good: its order key is free
});

/** The id of a stop, as the form sends it. */
function a(RouteStop $stop): int
{
    return $stop->id;
}

it('refuses a stop row that is not part of the plan', function () {
    $mine = ($this->save)(['plan_date' => '2026-10-14']);
    $other = ($this->save)(['plan_date' => '2026-10-15'], [stopInput(['label' => 'Elsewhere'])]);
    $foreignId = $other->stops[0]->id;

    expect(($this->complaints)([], [stopInput(['id' => (string) $foreignId])], null, $mine))->toBe(['stops.0.id'])
        ->and(($this->complaints)([], [stopInput(['id' => '999999'])], null, $mine))->toBe(['stops.0.id'])
        ->and(($this->complaints)([], [stopInput(['id' => (string) $mine->stops[0]->id]), stopInput(['id' => (string) $mine->stops[0]->id])], null, $mine))->toBe(['stops.1.id']);
});

it('keeps a published plan publishable: it cannot lose its last stop on the map', function () {
    $plan = makePlan($this->acme, $this->manager, $this->bde, $this->ahd);

    expect(($this->complaints)([], [stopInput(['latitude' => '', 'longitude' => ''])], null, $plan))->toBe(['stops'])
        ->and(($this->complaints)([], [stopInput(['label' => 'Moved', 'latitude' => '23.03', 'longitude' => '72.58'])], null, $plan))->toBe([]);
});

it('refuses to edit a cancelled plan, or one whose day is over', function () {
    $cancelled = makePlan($this->acme, $this->manager, $this->bde, $this->ahd, [], null, 'cancelled');
    expect(fn () => ($this->save)(['title' => 'x'], null, null, $cancelled))->toThrow(FieldRuleException::class, 'cancelled');

    $future = makePlan($this->acme, $this->manager, $this->bde, $this->ahd, ['plan_date' => '2026-10-13']);
    Carbon::setTestNow('2026-10-14 08:00:00');
    expect(fn () => ($this->save)(['plan_date' => '2026-10-13', 'title' => 'x'], null, null, $future))->toThrow(FieldRuleException::class, 'day is over');
});

it('fixes whom, when and where once attendance has been marked against the plan', function () {
    $plan = makePlan($this->acme, $this->manager, $this->bde, $this->ahd);
    inCompany($this->acme, fn () => app(AttendanceService::class)->mark($this->bde, HQ_LAT, HQ_LNG, 10));
    $keep = [stopInput(['id' => (string) $plan->stops()->first()->id])];      // the stop attendance was matched to stays

    expect(($this->complaints)(['plan_date' => '2026-10-13'], $keep, null, $plan->fresh()))->toBe(['plan.plan_date'])
        ->and(($this->complaints)(['user_id' => (string) $this->bde2->id], $keep, $this->admin, $plan->fresh()))->toBe(['plan.user_id'])
        ->and(($this->complaints)(['title' => 'Renamed round'], $keep, null, $plan->fresh()))->toBe([]);
});

it('keeps a stop that attendance or a refused attempt was measured against', function () {
    $plan = makePlan($this->acme, $this->manager, $this->bde, $this->ahd, [], [stopInput(['label' => 'Used']), stopInput(['label' => 'Spare', 'latitude' => '23.1', 'longitude' => '72.7'])]);
    inCompany($this->acme, fn () => app(AttendanceService::class)->mark($this->bde, HQ_LAT, HQ_LNG, 10));
    $used = $plan->stops[0];

    $withoutUsed = [stopInput(['id' => (string) $plan->stops[1]->id, 'label' => 'Spare', 'latitude' => '23.1', 'longitude' => '72.7'])];

    expect(($this->complaints)([], $withoutUsed, null, $plan->fresh()))->toBe(['stops'])
        ->and(RouteStop::query()->whereKey($used->id)->exists())->toBeTrue();
});

it('does not plan across companies', function () {
    $stranger = makeUser(['Sales Manager'], [$this->globex]);

    expect(fn () => inCompany($this->globex, fn () => $this->plans->save(null, planInput($this->bde, $this->globexBranch), $stranger)))->toThrow(ValidationException::class);

    $globexPerson = makeUser(['BDE / Field Salesperson'], [$this->globex]);
    $globexTeam = makeTeam($this->globex, $stranger, [$globexPerson], 'G1', 'Globex team');
    $plan = inCompany($this->globex, fn () => $this->plans->save(null, planInput($globexPerson, $this->globexBranch), $stranger));

    $this->actingAs($this->admin);
    expect(RoutePlan::query()->whereKey($plan->id)->exists())->toBeFalse();
});
