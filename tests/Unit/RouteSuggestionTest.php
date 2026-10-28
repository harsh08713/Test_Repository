<?php

use App\Modules\CRM\Services\RouteSuggestion;
use App\Modules\CRM\Support\GeoMath;

/** A point on a line of longitude: `$km` kilometres north of 23.0°N, 72.5°E (a stand-in for a stop). @return array{key: string, lat: float, lng: float} */
function stopNorth(string $key, float $km, float $eastKm = 0.0): array
{
    return ['key' => $key, 'lat' => 23.0 + $km / 111.19, 'lng' => 72.5 + $eastKm / (111.19 * cos(deg2rad(23.0)))];
}

beforeEach(fn () => $this->suggest = new RouteSuggestion);

it('puts stops on a line in the order of the line, from the start point', function () {
    $stops = [stopNorth('c', 30), stopNorth('a', 10), stopNorth('d', 40), stopNorth('b', 20)];

    $result = $this->suggest->suggest($stops, ['lat' => 23.0, 'lng' => 72.5]);

    expect($result['order'])->toBe(['a', 'b', 'c', 'd'])->and($result['changed'])->toBeTrue()->and($result['start'])->toBe('branch')
        ->and($result['distance_m'])->toBeGreaterThan(39900.0)->toBeLessThan(40100.0)             // 40 km out, never back
        ->and($result['saved_m'])->toBeGreaterThan(0.0)->and($result['current_m'])->toBeGreaterThan($result['distance_m']);
});

it('keeps the planner’s first stop first when there is no start point', function () {
    $stops = [stopNorth('b', 20), stopNorth('c', 30), stopNorth('a', 10), stopNorth('d', 40)];

    $result = $this->suggest->suggest($stops);

    expect($result['order'][0])->toBe('b')->and($result['start'])->toBe('first stop')
        ->and($result['distance_m'])->toBeLessThanOrEqual($result['current_m']);
});

it('does not suggest a change when the order is already the shortest', function () {
    $stops = [stopNorth('a', 10), stopNorth('b', 20), stopNorth('c', 30)];

    $result = $this->suggest->suggest($stops, ['lat' => 23.0, 'lng' => 72.5]);

    expect($result['order'])->toBe(['a', 'b', 'c'])->and($result['changed'])->toBeFalse()->and($result['saved_m'])->toBe(0.0)->and($result['distance_m'])->toBe($result['current_m']);
});

it('never makes a route longer, however the stops lie', function () {
    mt_srand(17);

    foreach (range(1, 20) as $round) {
        $stops = array_map(fn ($i) => ['key' => "s{$i}", 'lat' => 22.5 + mt_rand(0, 1000) / 1000, 'lng' => 72.0 + mt_rand(0, 1000) / 1000], range(1, 12));
        $result = $this->suggest->suggest($stops, ['lat' => 23.0, 'lng' => 72.5]);

        expect($result['distance_m'])->toBeLessThanOrEqual($result['current_m'])
            ->and($result['order'])->toHaveCount(12)->and(array_unique($result['order']))->toHaveCount(12)    // every stop once
            ->and(array_diff($result['order'], array_column($stops, 'key')))->toBe([]);
    }
});

it('finds a route at least as short as going from nearest to nearest', function () {
    // a long thin zig-zag where greedy goes wrong: the 2-opt pass has to repair it
    $stops = [stopNorth('p1', 5, 0), stopNorth('p2', 5.5, 6), stopNorth('p3', 11, 0), stopNorth('p4', 11.5, 6), stopNorth('p5', 17, 0), stopNorth('p6', 17.5, 6)];
    $worst = $this->suggest->suggest($stops);

    $reversed = array_reverse($stops);
    $again = $this->suggest->suggest($reversed, ['lat' => $stops[0]['lat'], 'lng' => $stops[0]['lng']]);

    expect($worst['distance_m'])->toBeLessThanOrEqual($worst['current_m'])->and($again['distance_m'])->toBeLessThan($again['current_m']);
});

it('leaves stops with no place on the map where the planner put them, and says so', function () {
    $stops = [
        stopNorth('far', 40), ['key' => 'reminder', 'lat' => null, 'lng' => null], stopNorth('near', 10), ['key' => 'zero', 'lat' => 0.0, 'lng' => 0.0], stopNorth('mid', 25),
    ];

    $result = $this->suggest->suggest($stops, ['lat' => 23.0, 'lng' => 72.5]);

    // slots 1 and 3 are the reminders' own; the three placeable stops take the other slots in route order
    expect($result['order'])->toBe(['near', 'reminder', 'mid', 'zero', 'far'])->and($result['unplaced'])->toBe(['reminder', 'zero']);
});

it('copes with no stops, one stop, and a start point that is not a place', function () {
    expect($this->suggest->suggest([])['order'])->toBe([])
        ->and($this->suggest->suggest([stopNorth('only', 5)])['order'])->toBe(['only'])
        ->and($this->suggest->suggest([stopNorth('only', 5)])['changed'])->toBeFalse();

    $result = $this->suggest->suggest([stopNorth('b', 20), stopNorth('a', 10)], ['lat' => 0.0, 'lng' => 0.0]);   // 0,0 is "no fix", not a start

    expect($result['start'])->toBe('first stop');
});

it('names the stops whose visit window the new order would put out of sequence', function () {
    $stops = [
        [...stopNorth('c', 30), 'from' => '09:00'], [...stopNorth('a', 10), 'from' => '14:00'], [...stopNorth('b', 20), 'from' => '10:30'], [...stopNorth('free', 15)],
    ];

    $result = $this->suggest->suggest($stops, ['lat' => 23.0, 'lng' => 72.5]);

    // route: a (14:00), free, b (10:30), c (09:00) — b and c are due before a stop already visited
    expect($result['order'])->toBe(['a', 'free', 'b', 'c'])->and($result['clashes'])->toBe(['b', 'c']);
});

it('is the same every time for the same stops', function () {
    $stops = [stopNorth('x', 12, 3), stopNorth('y', 12, -3), stopNorth('z', 30, 0), stopNorth('w', 3, 1)];

    expect($this->suggest->suggest($stops, ['lat' => 23.0, 'lng' => 72.5]))->toBe($this->suggest->suggest($stops, ['lat' => 23.0, 'lng' => 72.5]));
});

it('measures with the same distance the geofence uses', function () {
    $stops = [stopNorth('a', 10)];
    $result = $this->suggest->suggest([...$stops, stopNorth('b', 20)], ['lat' => 23.0, 'lng' => 72.5]);

    expect($result['distance_m'])->toBe(round(GeoMath::distanceMeters(23.0, 72.5, $stops[0]['lat'], $stops[0]['lng']) + GeoMath::distanceMeters($stops[0]['lat'], $stops[0]['lng'], 23.0 + 20 / 111.19, 72.5), 1));
});
