<?php

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\CRM\Enums\AttendanceDecision;
use App\Modules\CRM\Enums\RoutePlanStatus;
use App\Modules\CRM\Exceptions\AttendanceRefused;
use App\Modules\CRM\Exceptions\FieldPositionRefused;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Models\AttendanceRecord;
use App\Modules\CRM\Models\GeofenceViolation;
use App\Modules\CRM\Models\RoutePlan;
use App\Modules\CRM\Support\FieldInput;
use App\Modules\Shared\Services\SettingsService;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Marks attendance — the one rule everything else in this phase hangs on: **no route plan, no attendance**.
 *
 * `mark()` is what a person's own phone or browser calls. The server decides everything, in this order, and the first
 * failure refuses the attempt, writes it to the violation log and tells the person why:
 *
 *  1. today is not already marked (that is not a violation: it is just refused);
 *  2. FieldPositionGate (shared with saving a lead from the field) finds today's route plan — no plan, a draft, or a
 *     cancelled one all mean "no route plan" — and checks that the reported position is a real one, accurate enough, and
 *     inside the geofence of a stop of that plan (GeofenceService — nothing the client worked out is trusted).
 *
 * Only then is an attendance record written, with the **server's** clock, the position, accuracy, distance and radius
 * that decided it. The record is append-only. One record per person and day (a unique key backs it).
 *
 * `overrideViolation()` is the only other way in: where a manager has switched the override on for the company and holds
 * the `geofence.override` permission, they can accept a refused attempt of someone they manage (never their own), with a
 * reason, within a week. It does not erase the violation — a new record points back at it — and it is audited.
 *
 * Time and place come from the caller only as a position and an accuracy: `$at` exists for seeding and tests.
 */
final class AttendanceService
{
    /** How long after an attempt a manager can still accept it. */
    public const OVERRIDE_WINDOW_DAYS = 7;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly FieldPositionGate $gate,
        private readonly SalesScope $scope,
    ) {}

    /**
     * @throws AttendanceRefused with a message for the person; `reason` and `violationId` say what was logged
     */
    public function mark(User $user, mixed $latitude, mixed $longitude, mixed $accuracy, string $source = 'web', ?string $clientInfo = null, ?CarbonInterface $at = null): AttendanceRecord
    {
        $at ??= now();
        $day = $at->toDateString();

        $existing = AttendanceRecord::query()->where('user_id', $user->getKey())->where('attendance_date', $day)->first();
        if ($existing !== null) {
            throw AttendanceRefused::alreadyMarked($existing->marked_at->format('H:i'));
        }

        try {
            [$plan, $check] = $this->gate->verify($user, FieldPositionGate::ATTENDANCE, $latitude, $longitude, $accuracy, $source, $clientInfo, $at);
        } catch (FieldPositionRefused $e) {
            // the attempt is already in the violation log; the caller gets the attendance-flavoured refusal
            throw new AttendanceRefused($e->getMessage(), $e->reason, $e->violationId);
        }

        try {
            return DB::transaction(function () use ($user, $plan, $check, $source, $clientInfo, $at, $day) {
                $record = new AttendanceRecord;
                $record->forceFill([
                    'company_id' => (int) $plan->company_id,
                    'location_id' => $plan->location_id,
                    'user_id' => $user->getKey(),
                    'route_plan_id' => $plan->getKey(),
                    'route_stop_id' => $check->stop?->getKey(),
                    'attendance_date' => $day,
                    'marked_at' => $at,
                    'latitude' => FieldInput::coordinate($check->latitude),
                    'longitude' => FieldInput::coordinate($check->longitude),
                    'accuracy_m' => number_format((float) $check->accuracyM, 2, '.', ''),
                    'distance_m' => number_format((float) $check->distanceM, 1, '.', ''),
                    'radius_m' => (int) $check->radiusM,
                    'decision' => AttendanceDecision::Accepted->value,
                    'source' => FieldInput::source($source),
                    'client_info' => FieldInput::clientInfo($clientInfo),
                    'created_at' => now(),
                ])->save();

                return $record->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            // two taps at once: the first won, and this one is simply "already marked"
            throw AttendanceRefused::alreadyMarked($at->format('H:i'));
        }
    }

    /**
     * Accept a refused attempt on a manager's authority.
     *
     * @throws AuthorizationException when the manager may not (permission, or not their person)
     * @throws FieldRuleException when the rule says no (switched off, not overridable, too old, already marked)
     * @throws ValidationException when the reason is missing
     */
    public function overrideViolation(GeofenceViolation $violation, User $by, string $reason): AttendanceRecord
    {
        $reason = trim($reason);

        if (! $by->can('geofence.override')) {
            throw new AuthorizationException('You are not allowed to override a geofence violation.');
        }

        if (! (bool) $this->settings->get('geofence.manager_override', (int) $violation->company_id)) {
            throw new FieldRuleException('Overriding a geofence violation is switched off for this company (Company settings → Field sales).');
        }

        if ((int) $violation->user_id === (int) $by->getKey() || ! $this->scope->manages($by, (int) $violation->user_id)) {
            throw new AuthorizationException('You can accept attendance only for people on the teams you manage, and never your own.');
        }

        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:3', 'max:1000']], [
            'reason.required' => 'Please say why this attendance is being accepted.',
            'reason.min' => 'Please say why this attendance is being accepted.',
        ])->validate();

        return DB::transaction(function () use ($violation, $by, $reason) {
            $fresh = GeofenceViolation::query()->lockForUpdate()->findOrFail($violation->getKey());

            if ($fresh->attempt_type !== 'attendance' || ! $fresh->reason->isOverridable() || $fresh->route_plan_id === null) {
                throw new FieldRuleException('This refusal cannot be overridden: '.strtolower($fresh->reason->label()).'.');
            }

            if ($fresh->acceptedAs()->exists()) {
                throw new FieldRuleException('This attempt has already been accepted.');
            }

            $day = $fresh->attempted_at->toDateString();

            if ($fresh->attempted_at->lt(now()->subDays(self::OVERRIDE_WINDOW_DAYS)->startOfDay())) {
                throw new FieldRuleException('This attempt is more than '.self::OVERRIDE_WINDOW_DAYS.' days old and can no longer be accepted.');
            }

            if (AttendanceRecord::query()->where('user_id', $fresh->user_id)->where('attendance_date', $day)->exists()) {
                throw new FieldRuleException('Attendance has already been marked for that day.');
            }

            $plan = RoutePlan::query()->find($fresh->route_plan_id);

            if ($plan === null || $plan->status !== RoutePlanStatus::Published) {
                throw new FieldRuleException('The route plan for that day is no longer published.');
            }

            $record = new AttendanceRecord;
            $record->forceFill([
                'company_id' => (int) $fresh->company_id,
                'location_id' => $plan->location_id,
                'user_id' => $fresh->user_id,
                'route_plan_id' => $plan->getKey(),
                'route_stop_id' => $fresh->route_stop_id,
                'attendance_date' => $day,
                'marked_at' => $fresh->attempted_at,   // when the person actually was there; created_at says when it was accepted
                'latitude' => $fresh->latitude,
                'longitude' => $fresh->longitude,
                'accuracy_m' => $fresh->accuracy_m,
                'distance_m' => $fresh->distance_m ?? '0.0',
                'radius_m' => $fresh->radius_m ?? 0,
                'decision' => AttendanceDecision::AcceptedOverride->value,
                'source' => $fresh->source,
                'client_info' => $fresh->client_info,
                'override_by' => $by->getKey(),
                'override_reason' => $reason,
                'violation_id' => $fresh->getKey(),
                'created_at' => now(),
            ])->save();

            activity('attendance')
                ->causedBy($by)->performedOn($record)->event('override')
                ->withProperties(['attributes' => ['user_id' => (int) $fresh->user_id, 'attendance_date' => $day, 'decision' => AttendanceDecision::AcceptedOverride->value], 'violation_id' => $fresh->getKey(), 'violation_reason' => $fresh->reason->value, 'distance_m' => $fresh->distance_m, 'radius_m' => $fresh->radius_m, 'comment' => $reason])
                ->log('override');

            return $record->refresh();
        });
    }
}
