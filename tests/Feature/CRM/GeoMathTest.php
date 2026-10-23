<?php

use App\Modules\CRM\Support\GeoMath;

it('measures distances between two positions in metres', function () {
    expect(GeoMath::distanceMeters(HQ_LAT, HQ_LNG, HQ_LAT, HQ_LNG))->toBe(0.0)
        // a thousandth of a degree of latitude is 111 m, anywhere
        ->and(GeoMath::distanceMeters(23.0, 72.0, 23.001, 72.0))->toBeGreaterThan(111.0)->toBeLessThan(111.4)
        // Ahmedabad to Mumbai is about 440 km as the crow flies
        ->and(GeoMath::distanceMeters(HQ_LAT, HQ_LNG, 19.0760, 72.8777))->toBeGreaterThan(437000)->toBeLessThan(442000)
        // from the pole to the equator is a quarter of the way round
        ->and(GeoMath::distanceMeters(90.0, 0.0, 0.0, 0.0))->toBeGreaterThan(10_000_000)->toBeLessThan(10_020_000);
});

it('measures the same distance both ways round, and across the date line', function () {
    $there = GeoMath::distanceMeters(HQ_LAT, HQ_LNG, 21.1702, 72.8311);

    expect(GeoMath::distanceMeters(21.1702, 72.8311, HQ_LAT, HQ_LNG))->toBe($there)
        ->and(GeoMath::distanceMeters(0.0, 179.9999, 0.0, -179.9999))->toBeLessThan(25.0);
});

it('rounds a distance to one decimal', function () {
    $d = GeoMath::distanceMeters(23.0, 72.0, 23.0005, 72.0003);

    expect(round($d, 1))->toBe($d);
});

it('reads a number only from a number or a numeric string', function () {
    expect(GeoMath::number('23.0225'))->toBe(23.0225)->and(GeoMath::number(' 23.0225 '))->toBe(23.0225)->and(GeoMath::number(12))->toBe(12.0)->and(GeoMath::number(0.5))->toBe(0.5)
        ->and(GeoMath::number('abc'))->toBeNull()->and(GeoMath::number(''))->toBeNull()->and(GeoMath::number(null))->toBeNull()
        ->and(GeoMath::number(true))->toBeNull()->and(GeoMath::number([1]))->toBeNull()->and(GeoMath::number(NAN))->toBeNull()->and(GeoMath::number(INF))->toBeNull()
        ->and(GeoMath::number('NaN'))->toBeNull()->and(GeoMath::number('1e400'))->toBeNull();
});

it('accepts only a real position', function () {
    expect(GeoMath::isPosition(23.0225, 72.5714))->toBeTrue()->and(GeoMath::isPosition(-90.0, 180.0))->toBeTrue()->and(GeoMath::isPosition(90.0, -180.0))->toBeTrue()
        ->and(GeoMath::isPosition(90.0001, 0.5))->toBeFalse()->and(GeoMath::isPosition(-90.1, 0.5))->toBeFalse()
        ->and(GeoMath::isPosition(10.0, 180.0001))->toBeFalse()->and(GeoMath::isPosition(10.0, -181.0))->toBeFalse()
        ->and(GeoMath::isPosition(0.0, 0.0))->toBeFalse()      // what a device with no fix reports
        ->and(GeoMath::isPosition(0.0, 10.0))->toBeTrue()->and(GeoMath::isPosition(10.0, 0.0))->toBeTrue()
        ->and(GeoMath::isPosition(null, 10.0))->toBeFalse()->and(GeoMath::isPosition(10.0, null))->toBeFalse();
});
