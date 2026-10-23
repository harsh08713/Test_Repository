<?php

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\CRM\Enums\RoutePlanStatus;
use App\Modules\CRM\Enums\ViolationReason;
use App\Modules\CRM\Exceptions\FieldPositionRefused;
use App\Modules\CRM\Models\GeofenceViolation;
use App\Modules\CRM\Models\RoutePlan;
use App\Modules\CRM\Support\FieldInput;
use App\Modules\CRM\Support\GeofenceCheck;
use App\Modules\CRM\Support\GeoMath;
use App\Modules\Shared\Tenancy\TenantContext;
use Carbon\CarbonInterface;

/**
 * "Is this person, standing where their device says, allowed to do this field action now?" — the one answer attendance and
 * a lead saved from the field both need, so the two cannot drift apart.
 *
 * In order, and the first failure refuses the attempt, writes it to the violation log (with what it was for) and says why:
 *
 *  1. the person has a **published** route plan for today — none, a draft or a cancelled one all mean "no route plan";
 *  2. the reported position is a real one, accurate enough and inside the geofence of a stop of that plan (GeofenceService).
 *
 * Nothing the device computed is trusted: it sends a position and an accuracy; the plan, the day, the distance, the radius
 * and the verdict are the server's. It decides and logs; what the action then does (record attendance, save a lead) is the
 * caller's business.
 */
final class FieldPositionGate
{
    public const ATTENDANCE = 'attendance';

    public const LEAD_SAVE = 'lead_save';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly GeofenceService $geofence,
    ) {}

    /**
     * @param  self::ATTENDANCE|self::LEAD_SAVE  $attemptType
     * @return array{0: RoutePlan, 1: GeofenceCheck} today's plan and the check that let the position through
     *
     * @throws FieldPositionRefused
     */
    public function verify(User $user, string $attemptType, mixed $latitude, mixed $longitude, mixed $accuracy, string $source = 'web', ?string $clientInfo = null, ?CarbonInterface $at = null): array
    {
        $at ??= now();

        $plan = RoutePlan::query()->where('user_id', $user->getKey())->where('plan_date', $at->toDateString())->first();

        $problem = $this->planProblem($plan, $attemptType);
        if ($problem !== null) {
            [$reason, $message] = $problem;

            throw $this->refuse($user, $attemptType, $plan, $reason, $message, null, $latitude, $longitude, $accuracy, $source, $clientInfo, $at);
        }

        $check = $this->geofence->check($plan, $latitude, $longitude, $accuracy);

        if (! $check->allowed) {
            throw $this->refuse($user, $attemptType, $plan, $check->reason ?? ViolationReason::OutsideGeofence, $check->message, $check, $latitude, $longitude, $accuracy, $source, $clientInfo, $at);
        }

        return [$plan, $check];
    }

    /**
     * Is there a usable plan? Null when there is; otherwise the reason and what to tell the person.
     *
     * @return array{0: ViolationReason, 1: string}|null
     */
    public function planProblem(?RoutePlan $plan, string $attemptType = self::ATTENDANCE): ?array
    {
        $what = $attemptType === self::LEAD_SAVE ? 'a lead cannot be saved from the field' : 'attendance cannot be marked';

        if ($plan === null) {
            return [ViolationReason::NoRoutePlan, "You have no route plan for today, so {$what}. Ask your manager to plan your day."];
        }

        return match ($plan->status) {
            RoutePlanStatus::Published => null,
            RoutePlanStatus::Draft => [ViolationReason::PlanNotPublished, "Your route plan for today has not been published yet, so {$what}. Ask your manager to publish it."],
            RoutePlanStatus::Cancelled => [ViolationReason::PlanCancelled, "Your route plan for today has been cancelled, so {$what}. Ask your manager."],
        };
    }

    private function refuse(User $user, string $attemptType, ?RoutePlan $plan, ViolationReason $reason, string $message, ?GeofenceCheck $check, mixed $latitude, mixed $longitude, mixed $accuracy, string $source, ?string $clientInfo, CarbonInterface $at): FieldPositionRefused
    {
        $lat = $check?->latitude;
        $lng = $check?->longitude;

        // Only a position that is really one is stored as numbers; whatever else the device sent is kept as text.
        $valid = GeoMath::isPosition($lat ?? GeoMath::number($latitude), $lng ?? GeoMath::number($longitude));
        $latitudeNumber = $lat ?? ($valid ? GeoMath::number($latitude) : null);
        $longitudeNumber = $lng ?? ($valid ? GeoMath::number($longitude) : null);
        $accuracyNumber = $check->accuracyM ?? GeoMath::number($accuracy);

        $violation = new GeofenceViolation;
        $violation->forceFill([
            'company_id' => (int) ($plan->company_id ?? $this->tenant->activeCompanyId()),
            'location_id' => $plan?->location_id,
            'user_id' => $user->getKey(),
            'route_plan_id' => $plan?->getKey(),
            'route_stop_id' => $check?->stop?->getKey(),
            'attempt_type' => $attemptType,
            'reason' => $reason->value,
            'message' => mb_substr($message, 0, 255),
            'latitude' => $valid && $latitudeNumber !== null ? FieldInput::coordinate($latitudeNumber) : null,
            'longitude' => $valid && $longitudeNumber !== null ? FieldInput::coordinate($longitudeNumber) : null,
            'raw_coordinates' => $valid ? null : FieldInput::raw($latitude, $longitude),
            'accuracy_m' => $accuracyNumber !== null && $accuracyNumber > 0 && $accuracyNumber < 1000000 ? number_format($accuracyNumber, 2, '.', '') : null,
            'distance_m' => $check?->distanceM !== null ? number_format($check->distanceM, 1, '.', '') : null,
            'radius_m' => $check?->radiusM,
            'source' => FieldInput::source($source),
            'client_info' => FieldInput::clientInfo($clientInfo),
            'attempted_at' => $at,
        ])->save();

        return new FieldPositionRefused($message, $reason, (int) $violation->getKey(), $attemptType);
    }
}
