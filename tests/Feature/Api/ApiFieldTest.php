<?php

use App\Modules\CRM\Enums\LeadChannel;
use App\Modules\CRM\Http\Livewire\Attendance\ViolationList;
use App\Modules\CRM\Models\AttendanceRecord;
use App\Modules\CRM\Models\GeofenceViolation;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Services\RoutePlanService;
use App\Modules\Shared\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');

    $this->acme = makeCompany('ACME');
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->geo = seedGeography($this->acme);
    $this->ahd = makeLocation($this->acme, 'AHD');
    $this->mum = makeLocation($this->acme, 'MUM');

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->telecaller = makeUser(['Tele-caller'], [$this->acme]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde]);

    // a reading from where the device is: about 111 m of latitude per 0.001°, so 0.0300 is ~830 m north of the stop
    $this->at = fn (mixed $lat = HQ_LAT, mixed $lng = HQ_LNG, mixed $accuracy = 12) => ['position' => ['latitude' => $lat, 'longitude' => $lng, 'accuracy_m' => $accuracy]];
    $this->plan = fn (string $upTo = 'published') => makePlan($this->acme, $this->manager, $this->bde, $this->ahd, [], null, $upTo);
    $this->lead = fn (array $over = []) => ['name' => 'Shree Ganesh Traders', 'contact_person' => 'Meera Shah', 'mobile' => nextMobile(), ...$over];
    $this->violations = fn () => GeofenceViolation::query()->orderBy('id')->get();
});

afterEach(fn () => Carbon::setTestNow());

// ======================================================================================================
// Today's route plan
// ======================================================================================================

it('gives a salesperson their own plan for today, with the radius that will be applied to each stop', function () {
    ($this->plan)();

    $response = apiCall('GET', '/api/v1/route-plan/today', as: $this->bde)->assertOk();
    $data = $response->json('data');

    expect($data['date'])->toBe('2026-10-12')->and($data['status'])->toBe('published')->and($data['branch']['code'])->toBe('AHD')
        ->and($data['stops'])->toHaveCount(1)
        ->and($data['stops'][0])->toMatchArray(['label' => 'Naroda GIDC', 'latitude' => HQ_LAT, 'longitude' => HQ_LNG, 'radius_m' => 500])
        ->and($data['geofence'])->toBe(['plan_radius_m' => null, 'default_radius_m' => 500, 'max_accuracy_m' => 150])
        ->and($data['can_mark_attendance'])->toBeTrue()->and($data['attendance'])->toBeNull();
});

it('says so — and why — when there is no plan for today', function () {
    $response = apiCall('GET', '/api/v1/route-plan/today', as: $this->bde)->assertOk();

    expect($response->json('data'))->toBeNull()->and($response->json('meta.reason'))->toBe('no_route_plan');
});

it('shows a draft plan as a draft, which cannot yet be used', function () {
    ($this->plan)('draft');

    $data = apiCall('GET', '/api/v1/route-plan/today', as: $this->bde)->json('data');

    expect($data['status'])->toBe('draft')->and($data['can_mark_attendance'])->toBeFalse();
});

it('never shows someone else’s plan, and keeps it from people who have no field plan', function () {
    makePlan($this->acme, $this->manager, $this->bde, $this->ahd);
    $other = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);

    expect(apiCall('GET', '/api/v1/route-plan/today', as: $other)->json('data'))->toBeNull();
    apiCall('GET', '/api/v1/route-plan/today', as: $this->telecaller)->assertForbidden();
});

// ======================================================================================================
// Attendance
// ======================================================================================================

it('marks attendance from the device’s position, and the server says how far from the stop it was', function () {
    ($this->plan)();

    $response = apiCall('POST', '/api/v1/attendance', ($this->at)(HQ_LAT + 0.0010), $this->bde, idem())->assertCreated();

    expect($response->json('data.decision'))->toBe('accepted')->and($response->json('data.radius_m'))->toBe(500)
        ->and($response->json('data.distance_m'))->toBeGreaterThan(100)->toBeLessThan(120)
        ->and($response->json('data.stop.label'))->toBe('Naroda GIDC')
        ->and($response->json('data.marked_at'))->toBe('2026-10-12T09:30:00+05:30');

    $record = AttendanceRecord::query()->sole();
    expect($record->user_id)->toBe($this->bde->id)->and($record->source)->toBe('mobile');

    // and the plan now shows it
    $plan = apiCall('GET', '/api/v1/route-plan/today', as: $this->bde)->json('data');
    expect($plan['attendance']['id'])->toBe($record->id)->and($plan['can_mark_attendance'])->toBeFalse();
});

it('refuses attendance without a route plan with a clear 422, and logs the attempt', function () {
    $response = apiCall('POST', '/api/v1/attendance', ($this->at)(), $this->bde, idem())->assertStatus(422);

    $entry = ($this->violations)()->sole();

    expect($response->json('error.code'))->toBe('no_route_plan')->and($response->json('error.message'))->toContain('no route plan for today')
        ->and($response->json('error.details.violation_id'))->toBe($entry->id)->and($response->json('error.details.attempt'))->toBe('attendance')
        ->and($entry->attempt_type)->toBe('attendance')->and($entry->source)->toBe('mobile')->and(AttendanceRecord::query()->count())->toBe(0);
});

it('refuses a position outside the geofence with a 422 that says how far, and logs the attempt', function () {
    ($this->plan)();

    $response = apiCall('POST', '/api/v1/attendance', ($this->at)(HQ_LAT + 0.0075), $this->bde, idem())->assertStatus(422);

    $entry = ($this->violations)()->sole();

    expect($response->json('error.code'))->toBe('outside_geofence')->and($response->json('error.message'))->toContain('from the nearest stop')->toContain('within 500 m')
        ->and($response->json('error.details.reason_label'))->toBe('Outside the geofence')
        ->and($entry->reason->value)->toBe('outside_geofence')->and((float) $entry->distance_m)->toBeGreaterThan(800)->and($entry->radius_m)->toBe(500)
        ->and(AttendanceRecord::query()->count())->toBe(0);
});

it('refuses a reading that is too inaccurate, or not a position at all — and logs both', function () {
    ($this->plan)();

    apiCall('POST', '/api/v1/attendance', ($this->at)(HQ_LAT, HQ_LNG, 400), $this->bde, idem())->assertStatus(422)->assertJsonPath('error.code', 'low_accuracy');
    apiCall('POST', '/api/v1/attendance', ($this->at)('north', 'east', 10), $this->bde, idem())->assertStatus(422)->assertJsonPath('error.code', 'invalid_gps');
    apiCall('POST', '/api/v1/attendance', ($this->at)(0, 0, 10), $this->bde, idem())->assertStatus(422)->assertJsonPath('error.code', 'invalid_gps');

    $log = ($this->violations)();
    expect($log->pluck('reason')->map->value->all())->toBe(['low_accuracy', 'invalid_gps', 'invalid_gps'])
        ->and($log[1]->raw_coordinates)->toBe('north, east')->and($log[1]->latitude)->toBeNull();
});

it('says "already marked" with a 409, which is not a violation', function () {
    ($this->plan)();
    apiCall('POST', '/api/v1/attendance', ($this->at)(), $this->bde, idem())->assertCreated();

    apiCall('POST', '/api/v1/attendance', ($this->at)(), $this->bde, idem())->assertStatus(409)->assertJsonPath('error.code', 'already_marked');

    expect(($this->violations)())->toHaveCount(0)->and(AttendanceRecord::query()->count())->toBe(1);
});

it('asks for a missing or malformed position as a plain validation error, without logging a violation', function () {
    ($this->plan)();

    $missing = apiCall('POST', '/api/v1/attendance', [], $this->bde, idem())->assertStatus(422);
    expect($missing->json('error.code'))->toBe('validation_failed')->and(array_keys($missing->json('error.fields')))->toContain('position');

    $nested = apiCall('POST', '/api/v1/attendance', ['position' => ['latitude' => [1], 'longitude' => HQ_LNG, 'accuracy_m' => 10]], $this->bde, idem())->assertStatus(422);
    expect(array_keys($nested->json('error.fields')))->toBe(['position.latitude'])->and(($this->violations)())->toHaveCount(0);
});

it('needs an Idempotency-Key to mark attendance, and answers a repeat with the first answer', function () {
    ($this->plan)();

    apiCall('POST', '/api/v1/attendance', ($this->at)(), $this->bde)->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');

    $key = idem();
    $token = apiToken($this->bde);
    $first = apiCall('POST', '/api/v1/attendance', ($this->at)(), $token, $key)->assertCreated();
    $again = apiCall('POST', '/api/v1/attendance', ($this->at)(), $token, $key)->assertCreated();

    expect($again->headers->get('Idempotent-Replayed'))->toBe('true')->and($again->json('data.id'))->toBe($first->json('data.id'))
        ->and($first->headers->get('Idempotent-Replayed'))->toBeNull()
        ->and(AttendanceRecord::query()->count())->toBe(1);
});

it('keeps attendance to the people who may mark it, and lists only one’s own', function () {
    ($this->plan)();
    apiCall('POST', '/api/v1/attendance', ($this->at)(), $this->bde, idem())->assertCreated();

    apiCall('POST', '/api/v1/attendance', ($this->at)(), $this->manager, idem())->assertForbidden();
    apiCall('POST', '/api/v1/attendance', ($this->at)(), $this->telecaller, idem())->assertForbidden();

    $mine = apiCall('GET', '/api/v1/attendance', as: $this->bde)->assertOk();
    expect($mine->json('data'))->toHaveCount(1)->and($mine->json('meta.pagination.total'))->toBe(1);

    // a manager can look at attendance on the screens; the app's list is the person's own
    expect(apiCall('GET', '/api/v1/attendance', as: $this->manager)->json('data'))->toBe([]);
});

// ======================================================================================================
// A lead saved from the field
// ======================================================================================================

it('saves a lead entered inside the geofence — in the plan’s branch, whatever branch the device names', function () {
    ($this->plan)();

    $response = apiCall('POST', '/api/v1/leads', [...($this->lead)(['location_id' => $this->mum->id]), ...($this->at)(HQ_LAT + 0.0010)], $this->bde, idem())->assertCreated();

    $lead = Lead::query()->sole();

    expect($response->json('data.channel'))->toBe('field')->and($lead->entry_channel)->toBe(LeadChannel::Field)
        ->and($lead->location_id)->toBe($this->ahd->id)
        ->and($lead->lead_number)->toStartWith('LD/AHD/26-27/')
        ->and($lead->latitude)->toBe('23.0235000')->and($lead->longitude)->toBe('72.5714000')
        ->and($response->json('data.position'))->toBe(['latitude' => 23.0235, 'longitude' => 72.5714])
        ->and($response->json('data.source.name'))->toBe('Field')
        ->and($response->json('data.owner.id'))->toBe($this->bde->id)
        ->and($response->json('data.permissions.update'))->toBeTrue()
        ->and($response->headers->get('Location'))->toBe("/api/v1/leads/{$lead->id}")
        ->and(($this->violations)())->toHaveCount(0);
});

it('refuses a lead saved outside the geofence with a 422, saves nothing, and logs the attempt', function () {
    ($this->plan)();

    $response = apiCall('POST', '/api/v1/leads', [...($this->lead)(), ...($this->at)(HQ_LAT + 0.0075)], $this->bde, idem())->assertStatus(422);

    $entry = ($this->violations)()->sole();

    expect($response->json('error.code'))->toBe('outside_geofence')->and($response->json('error.message'))->toContain('from the nearest stop')
        ->and($response->json('error.details.attempt'))->toBe('lead_save')->and($response->json('error.details.violation_id'))->toBe($entry->id)
        ->and($entry->attempt_type)->toBe('lead_save')->and($entry->user_id)->toBe($this->bde->id)->and($entry->source)->toBe('mobile')
        ->and($entry->route_plan_id)->not->toBeNull()
        ->and(Lead::query()->count())->toBe(0);
});

it('refuses a field lead without a usable plan: none, a draft, a cancelled one', function () {
    $refused = fn () => apiCall('POST', '/api/v1/leads', [...($this->lead)(), ...($this->at)()], $this->bde, idem())->assertStatus(422);

    $none = $refused();
    expect($none->json('error.code'))->toBe('no_route_plan')->and($none->json('error.message'))->toContain('a lead cannot be saved from the field');

    $plan = ($this->plan)('draft');
    expect($refused()->json('error.code'))->toBe('plan_not_published');

    inCompany($this->acme, fn () => app(RoutePlanService::class)->cancel($plan->fresh(), 'Called off', $this->manager));
    expect($refused()->json('error.code'))->toBe('plan_cancelled');

    expect(Lead::query()->count())->toBe(0)->and(($this->violations)()->pluck('attempt_type')->unique()->all())->toBe(['lead_save']);
});

it('refuses a field lead from a reading that is too inaccurate or not a position, and logs both', function () {
    ($this->plan)();

    apiCall('POST', '/api/v1/leads', [...($this->lead)(), ...($this->at)(HQ_LAT, HQ_LNG, 900)], $this->bde, idem())->assertStatus(422)->assertJsonPath('error.code', 'low_accuracy');
    apiCall('POST', '/api/v1/leads', [...($this->lead)(), ...($this->at)('?', '?', 10)], $this->bde, idem())->assertStatus(422)->assertJsonPath('error.code', 'invalid_gps');

    expect(($this->violations)()->pluck('reason')->map->value->all())->toBe(['low_accuracy', 'invalid_gps'])->and(Lead::query()->count())->toBe(0);
});

it('requires a position from someone who works in the field, as a validation error', function () {
    ($this->plan)();

    $response = apiCall('POST', '/api/v1/leads', ($this->lead)(), $this->bde, idem())->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed')->and(array_keys($response->json('error.fields')))->toContain('position')
        ->and(Lead::query()->count())->toBe(0);
});

it('does not log a violation for a lead that merely fails its own validation', function () {
    ($this->plan)();

    $response = apiCall('POST', '/api/v1/leads', [...($this->lead)(['mobile' => '12345']), ...($this->at)()], $this->bde, idem())->assertStatus(422);

    expect($response->json('error.code'))->toBe('validation_failed')->and($response->json('error.fields'))->toHaveKey('mobile')
        ->and(($this->violations)())->toHaveCount(0)->and(Lead::query()->count())->toBe(0);
});

it('warns about a duplicate mobile number and saves a separate inquiry only when told to', function () {
    ($this->plan)();
    $mobile = nextMobile();
    makeLead($this->acme, $this->bde, $this->ahd, ['mobile' => $mobile, 'name' => 'Earlier inquiry']);

    $warned = apiCall('POST', '/api/v1/leads', [...($this->lead)(['mobile' => $mobile]), ...($this->at)()], $this->bde, idem())->assertStatus(422);
    expect($warned->json('error.fields.mobile.0'))->toContain('An open lead already has this mobile number')->and(Lead::query()->count())->toBe(1);

    apiCall('POST', '/api/v1/leads', [...($this->lead)(['mobile' => $mobile, 'acknowledge_duplicate' => true]), ...($this->at)()], $this->bde, idem())->assertCreated();
    expect(Lead::query()->count())->toBe(2);
});

it('lets the office enter a lead without a position: its branch is their own choice, and it is a typed-in lead', function () {
    $response = apiCall('POST', '/api/v1/leads', [...($this->lead)(['location_id' => $this->mum->id, 'assigned_to' => $this->bde->id, 'lead_source_id' => leadSourceId($this->acme, 'TELECALLER')])], $this->telecaller, idem());

    // a tele-caller may not hand leads out: whatever owner was sent, they own what they enter
    $response->assertCreated();
    $lead = Lead::query()->sole();

    expect($lead->entry_channel)->toBe(LeadChannel::Manual)->and($lead->location_id)->toBe($this->mum->id)->and($lead->assigned_to)->toBe($this->telecaller->id)
        ->and($lead->latitude)->toBeNull()->and(($this->violations)())->toHaveCount(0);

    // a manager can give it to someone on their team
    apiCall('POST', '/api/v1/leads', ($this->lead)(['location_id' => $this->ahd->id, 'assigned_to' => $this->bde->id, 'lead_source_id' => leadSourceId($this->acme, 'REFERENCE')]), $this->manager, idem())->assertCreated()->assertJsonPath('data.owner.id', $this->bde->id);
});

it('ignores a position sent by someone who does not work in the field — it is neither used nor judged', function () {
    apiCall('POST', '/api/v1/leads', [...($this->lead)(['location_id' => $this->ahd->id, 'lead_source_id' => leadSourceId($this->acme, 'REFERENCE')]), ...($this->at)('?', '?', 9999)], $this->telecaller, idem())->assertCreated();

    expect(Lead::query()->sole()->latitude)->toBeNull()->and(($this->violations)())->toHaveCount(0);
});

it('needs an Idempotency-Key to save a lead, and a repeat of the same save creates no second lead', function () {
    ($this->plan)();
    $body = [...($this->lead)(), ...($this->at)()];
    $token = apiToken($this->bde);
    $key = idem();

    apiCall('POST', '/api/v1/leads', $body, $token)->assertStatus(400)->assertJsonPath('error.code', 'idempotency_key_required');

    $first = apiCall('POST', '/api/v1/leads', $body, $token, $key)->assertCreated();
    $again = apiCall('POST', '/api/v1/leads', $body, $token, $key)->assertCreated();

    expect($again->headers->get('Idempotent-Replayed'))->toBe('true')->and($again->json('data.id'))->toBe($first->json('data.id'))
        ->and(Lead::query()->count())->toBe(1);
});

it('uses the company’s own geofence radius and accuracy limit', function () {
    ($this->plan)();
    app(SettingsService::class)->set('geofence.radius_m', 1000, $this->acme->id);
    app(SettingsService::class)->set('geofence.max_accuracy_m', 50, $this->acme->id);

    // 830 m away: outside the default 500 m, inside 1,000 m
    apiCall('POST', '/api/v1/leads', [...($this->lead)(), ...($this->at)(HQ_LAT + 0.0075, HQ_LNG, 20)], $this->bde, idem())->assertCreated();
    apiCall('POST', '/api/v1/leads', [...($this->lead)(), ...($this->at)(HQ_LAT, HQ_LNG, 80)], $this->bde, idem())->assertStatus(422)->assertJsonPath('error.code', 'low_accuracy');
});

// ---- the violation log on the screens --------------------------------------------------------------------

it('shows a refused field lead in the violation log, marked as one, and offers no way to accept it', function () {
    ($this->plan)();
    apiCall('POST', '/api/v1/leads', [...($this->lead)(), ...($this->at)(HQ_LAT + 0.0075)], $this->bde, idem())->assertStatus(422);
    apiCall('POST', '/api/v1/attendance', ($this->at)(HQ_LAT + 0.0075), $this->bde, idem())->assertStatus(422);

    app(SettingsService::class)->set('geofence.manager_override', true, $this->acme->id);

    $leadAttempt = ($this->violations)()->firstWhere('attempt_type', 'lead_save');
    $attendanceAttempt = ($this->violations)()->firstWhere('attempt_type', 'attendance');

    Livewire::actingAs($this->manager)->test(ViolationList::class)
        ->assertSee('Lead saved in the field')->assertSee('Lead not saved')
        ->assertSeeHtml('wire:key="viol-'.$leadAttempt->id.'"')->assertSeeHtml('wire:key="viol-'.$attendanceAttempt->id.'"');

    expect(Gate::forUser($this->manager)->allows('override', $attendanceAttempt))->toBeTrue()
        ->and(Gate::forUser($this->manager)->allows('override', $leadAttempt))->toBeFalse();
});

it('keeps the violation log append-only for these entries too', function () {
    ($this->plan)();
    apiCall('POST', '/api/v1/leads', [...($this->lead)(), ...($this->at)(HQ_LAT + 0.0075)], $this->bde, idem())->assertStatus(422);

    $entry = ($this->violations)()->sole();

    expect(fn () => $entry->forceFill(['message' => 'edited'])->save())->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and(DB::table('geofence_violations')->count())->toBe(1);
});
