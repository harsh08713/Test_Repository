<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Support\GeoMath;

/**
 * Suggests an order for a day's stops. **Advice only**: it reads coordinates and returns an order and some numbers; it saves nothing, moves nothing and
 * knows nothing about permissions — the planner looks at it and chooses, and the plan is saved through RoutePlanService as ever.
 *
 *  - Distances are straight lines over the earth (`GeoMath`, the same haversine the geofence uses), not roads: a river, a ring road or a one-way street is
 *    invisible to it. A road-distance provider would be one more class behind this one; none is wired in (a provider's terms, keys and rate limits are the
 *    owner's to accept).
 *  - It starts from the given start point (the branch, when it has coordinates) or, failing that, from the first stop as the planner has it — and then
 *    visits the nearest stop that is left, and finally tries swapping stretches of the route (2-opt) while that shortens it. Ties go to the earlier stop, so
 *    the same stops always give the same order.
 *  - A stop with no coordinates cannot be placed: it keeps its place in the planner's order and is reported as unplaced (see `unplaced`).
 *  - Visit windows are **not** forced: `windowClashes()` lists the stops whose window starts earlier than that of a stop visited before it, so the planner
 *    can see what the new order would break.
 */
final class RouteSuggestion
{
    /** A route this long is checked for shortening; the plan limit is 50, so this is never reached by a real plan. */
    private const MAX_POINTS = 200;

    /**
     * @param  list<array{key: string, lat: float|null, lng: float|null, from?: string|null}>  $stops  in the planner's order
     * @param  array{lat: float, lng: float}|null  $start
     * @return array{order: list<string>, distance_m: float, current_m: float, saved_m: float, changed: bool, unplaced: list<string>, start: string, clashes: list<string>}
     */
    public function suggest(array $stops, ?array $start = null): array
    {
        $placed = [];
        $unplaced = [];

        foreach (array_slice($stops, 0, self::MAX_POINTS) as $stop) {
            if (GeoMath::isPosition($stop['lat'], $stop['lng'])) {
                $placed[] = ['key' => $stop['key'], 'lat' => (float) $stop['lat'], 'lng' => (float) $stop['lng'], 'from' => $stop['from'] ?? null];
            } else {
                $unplaced[] = $stop['key'];
            }
        }

        $origin = $start !== null && GeoMath::isPosition($start['lat'], $start['lng']) ? $start : null;
        $currentKeys = array_column($placed, 'key');
        $current = $this->length($placed, $origin);

        if (count($placed) < 2) {
            return $this->result($stops, $placed, $placed, $origin, $current, $current, $unplaced);
        }

        $best = $this->nearestNeighbour($placed, $origin);
        $best = $this->twoOpt($best, $origin);

        // never offer a route that is not shorter than the one the planner has: advice that makes things worse is noise
        $bestLength = $this->length($best, $origin);

        if ($bestLength >= $current - 0.05) {
            $best = $placed;
            $bestLength = $current;
        }

        return $this->result($stops, $placed, $best, $origin, $current, $bestLength, $unplaced, $currentKeys);
    }

    /**
     * @param  list<array{key: string, lat: float, lng: float, from: string|null}>  $points
     * @param  array{lat: float, lng: float}|null  $origin
     * @return list<array{key: string, lat: float, lng: float, from: string|null}>
     */
    private function nearestNeighbour(array $points, ?array $origin): array
    {
        $left = $points;
        $route = [];
        $here = $origin;

        if ($here === null) {
            $route[] = array_shift($left);   // no start point: the planner's first stop is where the day begins
            $here = ['lat' => $route[0]['lat'], 'lng' => $route[0]['lng']];
        }

        while ($left !== []) {
            $nearest = null;
            $nearestDistance = INF;

            foreach ($left as $index => $point) {
                $distance = GeoMath::distanceMeters($here['lat'], $here['lng'], $point['lat'], $point['lng']);

                if ($distance < $nearestDistance) {
                    $nearest = $index;
                    $nearestDistance = $distance;
                }
            }

            $route[] = $left[$nearest];
            $here = ['lat' => $left[$nearest]['lat'], 'lng' => $left[$nearest]['lng']];
            unset($left[$nearest]);
            $left = array_values($left);
        }

        return $route;
    }

    /**
     * Reverse a stretch of the route while that makes it shorter. With no start point the first stop stays first (it is where the day begins).
     *
     * @param  list<array{key: string, lat: float, lng: float, from: string|null}>  $route
     * @param  array{lat: float, lng: float}|null  $origin
     * @return list<array{key: string, lat: float, lng: float, from: string|null}>
     */
    private function twoOpt(array $route, ?array $origin): array
    {
        $first = $origin === null ? 1 : 0;
        $count = count($route);
        $length = $this->length($route, $origin);
        $improved = true;
        $rounds = 0;

        while ($improved && $rounds++ < 100) {
            $improved = false;

            for ($i = $first; $i < $count - 1; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $candidate = [...array_slice($route, 0, $i), ...array_reverse(array_slice($route, $i, $j - $i + 1)), ...array_slice($route, $j + 1)];
                    $candidateLength = $this->length($candidate, $origin);

                    if ($candidateLength < $length - 0.05) {
                        $route = $candidate;
                        $length = $candidateLength;
                        $improved = true;
                    }
                }
            }
        }

        return $route;
    }

    /**
     * @param  list<array{key: string, lat: float, lng: float, from: string|null}>  $route
     * @param  array{lat: float, lng: float}|null  $origin
     */
    private function length(array $route, ?array $origin): float
    {
        $total = 0.0;
        $previous = $origin;

        foreach ($route as $point) {
            if ($previous !== null) {
                $total += GeoMath::distanceMeters($previous['lat'], $previous['lng'], $point['lat'], $point['lng']);
            }

            $previous = ['lat' => $point['lat'], 'lng' => $point['lng']];
        }

        return round($total, 1);
    }

    /**
     * Put the unplaced stops back where the planner had them and say what the new order does to the visit windows.
     *
     * @param  list<array{key: string, lat: float|null, lng: float|null, from?: string|null}>  $stops
     * @param  list<array{key: string, lat: float, lng: float, from: string|null}>  $placed
     * @param  list<array{key: string, lat: float, lng: float, from: string|null}>  $route
     * @param  array{lat: float, lng: float}|null  $origin
     * @param  list<string>  $unplaced
     * @param  list<string>  $currentKeys
     * @return array{order: list<string>, distance_m: float, current_m: float, saved_m: float, changed: bool, unplaced: list<string>, start: string, clashes: list<string>}
     */
    private function result(array $stops, array $placed, array $route, ?array $origin, float $current, float $length, array $unplaced, array $currentKeys = []): array
    {
        $routeKeys = array_column($route, 'key');
        $queue = $routeKeys;
        $order = [];

        // each place the planner had for a placeable stop now takes the next stop of the suggested route; unplaceable stops stay put
        foreach ($stops as $stop) {
            $order[] = in_array($stop['key'], $unplaced, true) ? $stop['key'] : array_shift($queue);
        }

        $windows = array_column($placed, 'from', 'key');
        $clashes = [];
        $latest = null;

        foreach ($routeKeys as $key) {
            $from = $windows[$key] ?? null;

            if ($from === null || $from === '') {
                continue;
            }

            if ($latest !== null && $from < $latest) {
                $clashes[] = $key;
            }

            $latest = $latest === null || $from > $latest ? $from : $latest;
        }

        return [
            'order' => $order, 'distance_m' => $length, 'current_m' => $current, 'saved_m' => round(max(0.0, $current - $length), 1),
            'changed' => $routeKeys !== ($currentKeys === [] ? $routeKeys : $currentKeys), 'unplaced' => $unplaced,
            'start' => $origin === null ? 'first stop' : 'branch', 'clashes' => $clashes,
        ];
    }
}
