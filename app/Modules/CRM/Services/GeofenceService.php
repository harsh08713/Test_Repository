<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Enums\ViolationReason;
use App\Modules\CRM\Models\RoutePlan;
use App\Modules\CRM\Models\RouteStop;
use App\Modules\CRM\Support\GeofenceCheck;
use App\Modules\CRM\Support\GeoMath;
use App\Modules\Shared\Services\SettingsService;

/**
 * The server's own geofence decision: is the position a device reported good enough, and inside the geofence of a stop
 * on the person's route plan? Nothing the browser or the app computes is trusted — it only sends a position and how
 * accurate it says it is; distance, radius and verdict are worked out here.
 *
 *  - The position must be a real one (latitude −90…90, longitude −180…180, not 0,0) and come with an accuracy.
 *  - The accuracy must be no worse than `geofence.max_accuracy_m`: a reading that could be anywhere within a few
 *    hundred metres says nothing about a 500 m fence.
 *  - Each stop of the plan with a place on the map is a fence: its centre and its radius — the stop's own, else the
 *    plan's, else the company's `geofence.radius_m`. The position qualifies if it is within *any* of them; the stop that
 *    came closest to qualifying is reported either way.
 *
 * It makes the decision and writes nothing: AttendanceService records what came of it (and later the mobile lead-save
 * will reuse it unchanged).
 */
final class GeofenceService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function check(RoutePlan $plan, mixed $latitude, mixed $longitude, mixed $accuracy): GeofenceCheck
    {
        $lat = GeoMath::number($latitude);
        $lng = GeoMath::number($longitude);

        if (! GeoMath::isPosition($lat, $lng)) {
            return new GeofenceCheck(false, ViolationReason::InvalidGps, 'Your position could not be read. Switch on location services and try again.');
        }

        $accuracyM = GeoMath::number($accuracy);

        if ($accuracyM === null || $accuracyM <= 0) {
            return new GeofenceCheck(false, ViolationReason::InvalidGps, 'Your device did not say how accurate its position is, so it cannot be checked. Try again with location services on.', $lat, $lng);
        }

        $stops = $plan->stops()->get()->filter(fn (RouteStop $stop) => $stop->hasCoordinates());

        if ($stops->isEmpty()) {
            return new GeofenceCheck(false, ViolationReason::OutsideGeofence, 'Your route plan has no stop with a place on the map, so your position cannot be checked. Ask your manager to fix the plan.', $lat, $lng, $accuracyM);
        }

        $default = (int) $this->settings->get('geofence.radius_m', (int) $plan->company_id);

        // The stop closest to qualifying: the one with the most room to spare (distance minus its radius), the earlier stop on a tie.
        $best = null;
        foreach ($stops as $stop) {
            $distance = GeoMath::distanceMeters($lat, $lng, (float) $stop->latitude, (float) $stop->longitude);
            $radius = (int) ($stop->radius_m ?? $plan->geofence_radius_m ?? $default);
            $margin = $distance - $radius;

            if ($best === null || $margin < $best['margin']) {
                $best = ['stop' => $stop, 'distance' => $distance, 'radius' => $radius, 'margin' => $margin];
            }
        }

        $stop = $best['stop'];
        $distance = $best['distance'];
        $radius = $best['radius'];

        $maxAccuracy = (int) $this->settings->get('geofence.max_accuracy_m', (int) $plan->company_id);

        if ($accuracyM > $maxAccuracy) {
            return new GeofenceCheck(
                false, ViolationReason::LowAccuracy,
                sprintf('Your GPS is only accurate to about %s m, and %d m is needed. Move to open sky or switch on high-accuracy location, then try again.', $this->metres($accuracyM), $maxAccuracy),
                $lat, $lng, $accuracyM, $stop, $distance, $radius,
            );
        }

        if ($best['margin'] > 0) {
            return new GeofenceCheck(
                false, ViolationReason::OutsideGeofence,
                sprintf('You are %s m from the nearest stop on today’s plan (%s); you need to be within %d m.', $this->metres($distance), $stop->label, $radius),
                $lat, $lng, $accuracyM, $stop, $distance, $radius,
            );
        }

        return new GeofenceCheck(true, null, sprintf('Inside the geofence of %s (%s m away).', $stop->label, $this->metres($distance)), $lat, $lng, $accuracyM, $stop, $distance, $radius);
    }

    private function metres(float $value): string
    {
        return number_format($value, $value < 100 ? 1 : 0);
    }
}
