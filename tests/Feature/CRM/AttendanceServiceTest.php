<?php

use App\Modules\CRM\Enums\AttendanceDecision;
use App\Modules\CRM\Enums\ViolationReason;
use App\Modules\CRM\Exceptions\AttendanceRefused;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\AttendanceRecord;
use App\Modules\CRM\Models\GeofenceViolation;
use App\Modules\CRM\Models\RoutePlan;
use App\Modules\CRM\Services\AttendanceService;
use App\Modules\CRM\Services\RoutePlanService;
use App\Modules\CRM\Services\SalesTeamService;
use App\Modules\Shared\Models\ActivityLog;
use App\Modules\Shared\Services\SettingsService;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde]);
    $this->attendance = app(AttendanceService::class);

    // the person marks their own attendance, from where they stand: the position goes in, a record or a refusal comes out
    $this->mark = fn ($lat, $lng, $accuracy = 12, ?string $source = 'web', $user = null) => inCompany(
        $this->acme,
        fn () => $this->attendance->mark($user ?? $this->bde, $lat, $lng, $accuracy, $source ?? 'web'),
    );
    $this->refusal = function (callable $call): ?AttendanceRefused {
        try {
            $call();
        } catch (AttendanceRefused $e) {
            return $e;
        }

        return null;
    };
    $this->plan = fn (array $plan = [], ?array $stops = null, string $upTo = 'published') => makePlan($this->acme, $this->manager, $this->bde, $this->ahd, $plan, $stops, $upTo);

    // refuse the BDE once from outside the fence (about 834 m away) and hand back the log entry
    $this->refused = function (float $lat = 23.0300): GeofenceViolation {
        ($this->refusal)(fn () => ($this->mark)($lat, HQ_LNG, 9.0));

        return GeofenceViolation::query()->latest('id')->firstOrFail();
    };
});

afterEach(fn () => Carbon::setTestNow());

// ======================================================================================================
// No route plan, no attendance
// ======================================================================================================

it('refuses attendance when the person has no route plan for today, and logs the attempt', function () {
    $e = ($this->refusal)(fn () => ($this->mark)(HQ_LAT, HQ_LNG));

    expect($e)->not->toBeNull()->and($e->reason)->toBe(ViolationReason::NoRoutePlan)
        ->and($e->getMessage())->toContain('no route plan for today')->toContain('attendance cannot be marked')
        ->and(AttendanceRecord::query()->count())->toBe(0);

    $entry = GeofenceViolation::query()->sole();
    expect($entry->id)->toBe($e->violationId)->and($entry->user_id)->toBe($this->bde->id)->and($entry->reason)->toBe(ViolationReason::NoRoutePlan)
        ->and($entry->route_plan_id)->toBeNull()->and($entry->attempt_type)->toBe('attendance')->and($entry->source)->toBe('web')
        ->and($entry->attempted_at->toDateTimeString())->toBe('2026-10-12 09:30:00')
        // the position was real, so it is kept for the manager to see
        ->and($entry->latitude)->toBe('23.0225000')->and($entry->longitude)->toBe('72.5714000');
});

it('treats a draft plan, and a cancelled one, as no plan', function () {
    $draft = ($this->plan)([], null, 'draft');

    $e = ($this->refusal)(fn () => ($this->mark)(HQ_LAT, HQ_LNG));
    expect($e->reason)->toBe(ViolationReason::PlanNotPublished)->and($e->getMessage())->toContain('not been published');

    inCompany($this->acme, fn () => app(RoutePlanService::class)->cancel($draft->fresh(), 'Called off', $this->manager));
    $e = ($this->refusal)(fn () => ($this->mark)(HQ_LAT, HQ_LNG));
    expect($e->reason)->toBe(ViolationReason::PlanCancelled)->and($e->getMessage())->toContain('cancelled')
        ->and(AttendanceRecord::query()->count())->toBe(0)->and(GeofenceViolation::query()->count())->toBe(2);
});

it('does not use another day’s plan, or another person’s', function () {
    ($this->plan)(['plan_date' => '2026-10-13']);                                   // tomorrow
    $other = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    inCompany($this->acme, fn () => app(SalesTeamService::class)->addMember($this->team, $other->id, '2026-01-01', $this->manager));
    makePlan($this->acme, $this->manager, $other, $this->ahd);                       // somebody else, today

    expect(($this->refusal)(fn () => ($this->mark)(HQ_LAT, HQ_LNG))->reason)->toBe(ViolationReason::NoRoutePlan);
});

it('does not see a plan of another company, or of a branch the person does not work in', function () {
    $mumbai = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->mum]);
    inCompany($this->acme, fn () => app(SalesTeamService::class)->addMember($this->team, $mumbai->id, '2026-01-01', $this->manager));
    makePlan($this->acme, $this->manager, $mumbai, $this->mum);

    // the same person, later moved off Mumbai: the plan is of a branch they no longer work in, so it does not exist for them
    DB::table('location_user')->where('user_id', $mumbai->id)->delete();
    DB::table('location_user')->insert(['location_id' => $this->ahd->id, 'user_id' => $mumbai->id, 'created_at' => now(), 'updated_at' => now()]);
    app(TenantContext::class)->flush();
    $this->actingAs($mumbai);

    expect(($this->refusal)(fn () => $this->attendance->mark($mumbai, HQ_LAT, HQ_LNG, 10))->reason)->toBe(ViolationReason::NoRoutePlan);

    $outsider = makeUser(['BDE / Field Salesperson'], [$this->globex]);
    $this->actingAs($outsider);
    expect(($this->refusal)(fn () => $this->attendance->mark($outsider, HQ_LAT, HQ_LNG, 10))->reason)->toBe(ViolationReason::NoRoutePlan);
});

// ======================================================================================================
// Inside the geofence
// ======================================================================================================

it('marks attendance from inside the geofence, with the server’s clock and what decided it', function () {
    $plan = ($this->plan)();

    // about 167 m north of the stop; the radius is the company default of 500 m
    $record = ($this->mark)(23.0240, HQ_LNG, 18.5, 'mobile');

    expect($record->user_id)->toBe($this->bde->id)->and($record->route_plan_id)->toBe($plan->id)->and($record->route_stop_id)->toBe($plan->stops()->first()->id)
        ->and($record->attendance_date->toDateString())->toBe('2026-10-12')->and($record->marked_at->toDateTimeString())->toBe('2026-10-12 09:30:00')
        ->and($record->decision)->toBe(AttendanceDecision::Accepted)->and($record->source)->toBe('mobile')
        ->and($record->latitude)->toBe('23.0240000')->and($record->longitude)->toBe('72.5714000')->and($record->accuracy_m)->toBe('18.50')
        ->and((float) $record->distance_m)->toBeGreaterThan(160.0)->toBeLessThan(175.0)->and($record->radius_m)->toBe(500)
        ->and($record->location_id)->toBe($this->ahd->id)->and($record->company_id)->toBe($this->acme->id)
        ->and($record->override_by)->toBeNull()->and($record->violation_id)->toBeNull()
        ->and(GeofenceViolation::query()->count())->toBe(0);
});

it('accepts a position on the edge of the fence and refuses one just beyond it', function () {
    ($this->plan)([], [stopInput(['radius_m' => '200'])]);

    // 0.0018° of latitude is 200.15 m: just outside; 0.0017° is 189 m: inside
    $e = ($this->refusal)(fn () => ($this->mark)(23.0225 + 0.0018, HQ_LNG));
    expect($e->reason)->toBe(ViolationReason::OutsideGeofence);

    expect(($this->mark)(23.0225 + 0.0017, HQ_LNG)->radius_m)->toBe(200);
});

it('takes the stop’s own radius, then the plan’s, then the company’s', function () {
    // company 500 m; the plan 1000 m; the stop 100 m
    inCompany($this->acme, fn () => app(SettingsService::class)->set('geofence.radius_m', 500));
    $plan = ($this->plan)(['geofence_radius_m' => '1000'], [stopInput(['label' => 'Own radius', 'radius_m' => '100']), stopInput(['label' => 'Plan radius', 'latitude' => '23.0500', 'longitude' => '72.6000'])]);

    // 830 m from the second stop: outside the company's 500 but inside the plan's 1000
    expect(($this->mark)(23.0575, 72.6000)->radius_m)->toBe(1000);
});

it('uses the company’s default radius setting', function () {
    inCompany($this->acme, fn () => app(SettingsService::class)->set('geofence.radius_m', 100));
    ($this->plan)();

    // 167 m away: inside the 500 m default, outside this company's 100 m
    expect(($this->refusal)(fn () => ($this->mark)(23.0240, HQ_LNG))->reason)->toBe(ViolationReason::OutsideGeofence);

    inCompany($this->acme, fn () => app(SettingsService::class)->set('geofence.radius_m', 300));
    expect(($this->mark)(23.0240, HQ_LNG)->radius_m)->toBe(300);
});

it('qualifies a position at any stop of the plan, and records which one', function () {
    $plan = ($this->plan)([], [stopInput(['label' => 'First']), stopInput(['label' => 'Surat', 'latitude' => '21.1702', 'longitude' => '72.8311'])]);

    $record = ($this->mark)(21.1710, 72.8311);

    expect($record->route_stop_id)->toBe($plan->stops()->where('label', 'Surat')->value('id'));
});

it('ignores stops that have no place on the map', function () {
    $plan = ($this->plan)([], [stopInput(['label' => 'Reminder', 'latitude' => '', 'longitude' => '']), stopInput(['label' => 'Real'])]);

    expect(($this->mark)(HQ_LAT, HQ_LNG)->route_stop_id)->toBe($plan->stops()->where('label', 'Real')->value('id'));
});

// ======================================================================================================
// Refused: outside, inaccurate, unreadable
// ======================================================================================================

it('refuses a position outside every geofence, says how far, and logs what was reported', function () {
    $plan = ($this->plan)();

    // about 834 m north of the stop
    $e = ($this->refusal)(fn () => ($this->mark)(23.0300, HQ_LNG, 9.0));

    expect($e->reason)->toBe(ViolationReason::OutsideGeofence)->and($e->getMessage())->toContain('from the nearest stop')->toContain('Naroda GIDC')->toContain('500 m')
        ->and(AttendanceRecord::query()->count())->toBe(0);

    $entry = GeofenceViolation::query()->sole();
    expect($entry->reason)->toBe(ViolationReason::OutsideGeofence)->and($entry->route_plan_id)->toBe($plan->id)->and($entry->route_stop_id)->toBe($plan->stops()->first()->id)
        ->and((float) $entry->distance_m)->toBeGreaterThan(830.0)->toBeLessThan(840.0)->and($entry->radius_m)->toBe(500)
        ->and($entry->accuracy_m)->toBe('9.00')->and($entry->latitude)->toBe('23.0300000')->and($entry->location_id)->toBe($this->ahd->id)
        ->and($entry->message)->toBe($e->getMessage());
});

it('refuses a position that could be anywhere within a few hundred metres', function () {
    ($this->plan)();

    $e = ($this->refusal)(fn () => ($this->mark)(HQ_LAT, HQ_LNG, 400));
    expect($e->reason)->toBe(ViolationReason::LowAccuracy)->and($e->getMessage())->toContain('150 m is needed');

    // exactly at the limit is fine
    expect(($this->mark)(HQ_LAT, HQ_LNG, 150)->accuracy_m)->toBe('150.00');
});

it('lets the company decide how accurate is accurate enough', function () {
    ($this->plan)();
    inCompany($this->acme, fn () => app(SettingsService::class)->set('geofence.max_accuracy_m', 30));

    expect(($this->refusal)(fn () => ($this->mark)(HQ_LAT, HQ_LNG, 80))->reason)->toBe(ViolationReason::LowAccuracy)
        ->and(($this->mark)(HQ_LAT, HQ_LNG, 25)->decision)->toBe(AttendanceDecision::Accepted);
});

it('refuses a reading with no usable accuracy', function () {
    ($this->plan)();

    foreach ([null, '', 0, '0', -5, 'abc', [], true, NAN] as $bad) {
        $e = ($this->refusal)(fn () => ($this->mark)(HQ_LAT, HQ_LNG, $bad));

        expect($e?->reason)->toBe(ViolationReason::InvalidGps, 'accuracy '.(is_float($bad) && is_nan($bad) ? 'NAN' : json_encode($bad)));
    }

    expect(AttendanceRecord::query()->count())->toBe(0);
});

it('refuses coordinates that are not a position, and keeps what the device sent as text', function () {
    ($this->plan)();

    foreach ([['abc', '72.5'], [91, 72.5], [-91, 72.5], [23.0, 181], [23.0, -181], [0, 0], ['0', '0.0'], [null, null], [[1], [2]], ['', ''], [NAN, 72.5], [true, false]] as [$lat, $lng]) {
        $e = ($this->refusal)(fn () => ($this->mark)($lat, $lng));

        expect($e?->reason)->toBe(ViolationReason::InvalidGps, 'position '.json_encode([is_float($lat) && is_nan($lat) ? 'NaN' : $lat, $lng]));
    }

    expect(AttendanceRecord::query()->count())->toBe(0);

    $kept = GeofenceViolation::query()->where('raw_coordinates', 'abc, 72.5')->sole();
    expect($kept->latitude)->toBeNull()->and($kept->longitude)->toBeNull()->and($kept->reason)->toBe(ViolationReason::InvalidGps);
});

it('does not let a hostile reading overflow or break the log', function () {
    ($this->plan)();

    $e = ($this->refusal)(fn () => ($this->mark)(str_repeat('9', 400), str_repeat('8', 400), '99999999999999999999'));

    expect($e->reason)->toBe(ViolationReason::InvalidGps);

    $entry = GeofenceViolation::query()->latest('id')->first();
    expect(mb_strlen($entry->raw_coordinates))->toBeLessThanOrEqual(80)->and($entry->accuracy_m)->toBeNull();
});

it('refuses a plan with no stop on the map, whatever the position', function () {
    $draft = ($this->plan)([], [stopInput(['latitude' => '', 'longitude' => ''])], 'draft');

    // such a plan cannot be published; force it to prove the service does not trust that guard alone
    $draft->forceFill(['status' => 'published'])->save();

    $e = ($this->refusal)(fn () => ($this->mark)(HQ_LAT, HQ_LNG));

    expect($e->reason)->toBe(ViolationReason::OutsideGeofence)->and($e->getMessage())->toContain('no stop with a place on the map');
});

// ======================================================================================================
// Once a day, append-only
// ======================================================================================================

it('marks attendance once a day, and says so the second time without calling it a violation', function () {
    ($this->plan)();
    ($this->mark)(HQ_LAT, HQ_LNG);

    $e = ($this->refusal)(fn () => ($this->mark)(HQ_LAT, HQ_LNG));

    expect($e->reason)->toBeNull()->and($e->violationId)->toBeNull()->and($e->getMessage())->toContain('already marked')->toContain('09:30')
        ->and(AttendanceRecord::query()->count())->toBe(1)->and(GeofenceViolation::query()->count())->toBe(0);
});

it('lets a refused person try again from the right place, and the log keeps the first try', function () {
    ($this->plan)();

    expect(($this->refusal)(fn () => ($this->mark)(23.0300, HQ_LNG))->reason)->toBe(ViolationReason::OutsideGeofence);

    expect(($this->mark)(HQ_LAT, HQ_LNG)->decision)->toBe(AttendanceDecision::Accepted)->and(GeofenceViolation::query()->count())->toBe(1);
});

it('never lets a record or a log entry be changed or deleted', function () {
    ($this->plan)();
    ($this->refusal)(fn () => ($this->mark)(23.0300, HQ_LNG));
    $record = ($this->mark)(HQ_LAT, HQ_LNG);
    $violation = GeofenceViolation::query()->sole();

    expect(fn () => $record->forceFill(['latitude' => '1.0000000'])->save())->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $record->delete())->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $violation->forceFill(['message' => 'edited'])->save())->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $violation->delete())->toThrow(LogicException::class, 'append-only');
});

it('is stopped by the database too: one attendance per person and day', function () {
    $plan = ($this->plan)();
    $record = ($this->mark)(HQ_LAT, HQ_LNG);

    $twin = new AttendanceRecord;
    $twin->forceFill([...$record->only(['company_id', 'location_id', 'user_id', 'route_plan_id', 'attendance_date', 'latitude', 'longitude', 'accuracy_m', 'distance_m', 'radius_m', 'decision', 'source']), 'marked_at' => now(), 'created_at' => now()]);

    expect(fn () => inCompany($this->acme, fn () => $twin->save()))->toThrow(UniqueConstraintViolationException::class);
    expect($plan->attendance()->count())->toBe(1);
});

// ======================================================================================================
// A manager accepting a refused attempt
// ======================================================================================================

it('is switched off until the company switches it on', function () {
    ($this->plan)();
    $violation = ($this->refused)();

    expect(fn () => inCompany($this->acme, fn () => $this->attendance->overrideViolation($violation, $this->manager, 'GPS was poor')))
        ->toThrow(FieldRuleException::class, 'switched off');
    expect(AttendanceRecord::query()->count())->toBe(0);
});

it('lets a manager who holds the right accept an attempt of someone on their team, with a reason, and audits it', function () {
    ($this->plan)();
    inCompany($this->acme, fn () => app(SettingsService::class)->set('geofence.manager_override', true));
    $violation = ($this->refused)();
    $this->actingAs($this->manager);

    $record = inCompany($this->acme, fn () => $this->attendance->overrideViolation($violation, $this->manager, 'Customer premises are behind a hill; GPS drifts there'));

    expect($record->decision)->toBe(AttendanceDecision::AcceptedOverride)->and($record->override_by)->toBe($this->manager->id)
        ->and($record->override_reason)->toBe('Customer premises are behind a hill; GPS drifts there')->and($record->violation_id)->toBe($violation->id)
        ->and($record->user_id)->toBe($this->bde->id)->and($record->attendance_date->toDateString())->toBe('2026-10-12')
        // what the device really reported, and when: not the manager's position or time
        ->and($record->latitude)->toBe('23.0300000')->and($record->marked_at->toDateTimeString())->toBe($violation->attempted_at->toDateTimeString())
        ->and($record->radius_m)->toBe(500)->and((float) $record->distance_m)->toBe((float) $violation->distance_m);

    $audit = ActivityLog::query()->where('log_name', 'attendance')->where('event', 'override')->sole();
    expect((int) $audit->causer_id)->toBe($this->manager->id)->and($audit->properties['comment'])->toBe('Customer premises are behind a hill; GPS drifts there')
        ->and($audit->properties['violation_id'])->toBe($violation->id)->and($audit->properties['violation_reason'])->toBe('outside_geofence');

    // the refused attempt is still there, now pointing at what came of it
    expect(GeofenceViolation::query()->count())->toBe(1)->and($violation->fresh()->acceptedAs->id)->toBe($record->id);
});

it('refuses an override from someone without the right, or for someone who is not theirs, or their own', function () {
    ($this->plan)();
    inCompany($this->acme, fn () => app(SettingsService::class)->set('geofence.manager_override', true));
    $violation = ($this->refused)();

    $otherManager = makeUser(['Sales Manager'], [$this->acme]);
    makeTeam($this->acme, $otherManager, [], 'OTHER', 'Another team');
    $accounts = makeUser(['Accounts User'], [$this->acme]);

    foreach ([$this->bde, $accounts, $otherManager] as $user) {
        $this->actingAs($user);
        expect(fn () => inCompany($this->acme, fn () => $this->attendance->overrideViolation($violation, $user, 'Please')))->toThrow(AuthorizationException::class);
    }

    // somebody who sees everyone still cannot accept their own attempt
    $admin = makeUser(['Company Admin'], [$this->acme]);
    makePlan($this->acme, $admin, $admin, $this->ahd);
    $this->actingAs($admin);
    ($this->refusal)(fn () => inCompany($this->acme, fn () => $this->attendance->mark($admin, 23.0300, HQ_LNG, 9)));
    $own = GeofenceViolation::query()->where('user_id', $admin->id)->firstOrFail();

    expect(fn () => inCompany($this->acme, fn () => $this->attendance->overrideViolation($own, $admin, 'Mine, but fine')))->toThrow(AuthorizationException::class)
        ->and(AttendanceRecord::query()->count())->toBe(0);
});

it('needs a reason', function () {
    ($this->plan)();
    inCompany($this->acme, fn () => app(SettingsService::class)->set('geofence.manager_override', true));
    $violation = ($this->refused)();
    $this->actingAs($this->manager);

    foreach (['', '  ', 'ok'] as $reason) {
        expect(fn () => inCompany($this->acme, fn () => $this->attendance->overrideViolation($violation, $this->manager, $reason)))->toThrow(ValidationException::class);
    }
});

it('cannot override a refusal that is not about the position: no plan, or a reading that is no position', function () {
    inCompany($this->acme, fn () => app(SettingsService::class)->set('geofence.manager_override', true));
    $this->actingAs($this->manager);

    ($this->refusal)(fn () => ($this->mark)(HQ_LAT, HQ_LNG));                       // no plan
    $noPlan = GeofenceViolation::query()->latest('id')->first();
    expect(fn () => inCompany($this->acme, fn () => $this->attendance->overrideViolation($noPlan, $this->manager, 'Let it through')))->toThrow(FieldRuleException::class, 'cannot be overridden');

    ($this->plan)();
    ($this->refusal)(fn () => ($this->mark)(0, 0));                                  // not a position
    $unreadable = GeofenceViolation::query()->latest('id')->first();
    expect(fn () => inCompany($this->acme, fn () => $this->attendance->overrideViolation($unreadable, $this->manager, 'Let it through')))->toThrow(FieldRuleException::class, 'cannot be overridden')
        ->and(AttendanceRecord::query()->count())->toBe(0);
});

it('can accept an attempt only once, only while no attendance exists, and only for a week', function () {
    $plan = ($this->plan)();
    inCompany($this->acme, fn () => app(SettingsService::class)->set('geofence.manager_override', true));
    $violation = ($this->refused)();
    $second = ($this->refused)(23.0310);          // a second refused attempt the same day
    $this->actingAs($this->manager);

    inCompany($this->acme, fn () => $this->attendance->overrideViolation($violation, $this->manager, 'First time'));
    expect(fn () => inCompany($this->acme, fn () => $this->attendance->overrideViolation($violation->fresh(), $this->manager, 'Again')))->toThrow(FieldRuleException::class, 'already been accepted');

    // the day already has its attendance: the second attempt cannot make another
    expect(fn () => inCompany($this->acme, fn () => $this->attendance->overrideViolation($second, $this->manager, 'Another')))->toThrow(FieldRuleException::class, 'already been marked');

    // an attempt eight days old is too old
    Carbon::setTestNow('2026-10-21 09:30:00');
    $old = GeofenceViolation::query()->whereKey($violation->id)->first();
    $stale = new GeofenceViolation;
    $stale->forceFill([...$old->only(['company_id', 'location_id', 'user_id', 'route_plan_id', 'route_stop_id', 'attempt_type', 'reason', 'message', 'latitude', 'longitude', 'accuracy_m', 'distance_m', 'radius_m', 'source']), 'attempted_at' => '2026-10-12 08:00:00']);
    inCompany($this->acme, fn () => $stale->save());

    expect(fn () => inCompany($this->acme, fn () => $this->attendance->overrideViolation($stale, $this->manager, 'Too late')))->toThrow(FieldRuleException::class, 'more than 7 days');
});

it('does not accept attendance against a plan that has since been cancelled', function () {
    $plan = ($this->plan)();
    inCompany($this->acme, fn () => app(SettingsService::class)->set('geofence.manager_override', true));
    $violation = ($this->refused)();
    $this->actingAs($this->manager);

    inCompany($this->acme, fn () => app(RoutePlanService::class)->cancel($plan->fresh(), 'Called off after all', $this->manager));

    expect(fn () => inCompany($this->acme, fn () => $this->attendance->overrideViolation($violation, $this->manager, 'Accept it')))->toThrow(FieldRuleException::class, 'no longer published');
});

it('shows a company only its own refusals and records', function () {
    ($this->plan)();
    ($this->refusal)(fn () => ($this->mark)(23.0300, HQ_LNG));
    ($this->mark)(HQ_LAT, HQ_LNG);

    $globexAdmin = makeUser(['Company Admin'], [$this->globex]);
    $this->actingAs($globexAdmin);

    expect(GeofenceViolation::query()->count())->toBe(0)->and(AttendanceRecord::query()->count())->toBe(0)->and(RoutePlan::query()->count())->toBe(0);
});
