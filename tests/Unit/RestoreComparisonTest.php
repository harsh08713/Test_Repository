<?php

use App\Modules\Shared\Services\RestoreComparison;

/*
 * Whether a restored copy is faithful, decided from the numbers alone. Hand-made figures: a table with the same rows is OK; fewer rows restored than live is the live database having grown (WARN);
 * more rows restored than live is rows removed since (WARN); a missing table, a different column count, or an empty restore of a table that has rows is a FAIL; sessions and the like are skipped.
 */

it('passes a restore that matches and tells the difference between a warning and a failure', function () {
    $live = [
        'leads' => ['rows' => 120, 'columns' => 30], 'items' => ['rows' => 40, 'columns' => 22], 'orders' => ['rows' => 75, 'columns' => 18], 'notes' => ['rows' => 9, 'columns' => 5],
        'sessions' => ['rows' => 14, 'columns' => 6], 'brands' => ['rows' => 3, 'columns' => 8],
    ];
    $restored = [
        'leads' => ['rows' => 120, 'columns' => 30],     // the same: OK
        'items' => ['rows' => 38, 'columns' => 22],      // live has grown by two since the backup: WARN
        'orders' => ['rows' => 80, 'columns' => 18],     // five rows were removed since: WARN
        'notes' => ['rows' => 0, 'columns' => 5],        // restored empty: FAIL
        'sessions' => ['rows' => 0, 'columns' => 6],     // changes by itself: skipped, however different
        'brands' => ['rows' => 3, 'columns' => 7],       // a column is missing: FAIL
    ];

    $result = RestoreComparison::compare($live, $restored);
    $status = collect($result['tables'])->map->status->all();

    expect($status)->toBe(['brands' => 'FAIL', 'items' => 'WARN', 'leads' => 'OK', 'notes' => 'FAIL', 'orders' => 'WARN', 'sessions' => 'skipped'])
        ->and($result['failures'])->toHaveCount(2)->and($result['failures'][0])->toContain('brands: 7 columns restored but 8 live')->and($result['failures'][1])->toContain('notes: restored empty, but 9 rows live')
        ->and($result['warnings'])->toHaveCount(2)->and($result['warnings'][0])->toContain('items: 38 restored, 40 live (grown since)')->and($result['warnings'][1])->toContain('orders: 80 restored, 75 live (removed since)');
});

it('fails a restore that lacks a table, and passes an exact copy', function () {
    $live = ['leads' => ['rows' => 5, 'columns' => 3], 'items' => ['rows' => 2, 'columns' => 4]];

    $missing = RestoreComparison::compare($live, ['leads' => ['rows' => 5, 'columns' => 3]]);
    expect($missing['failures'])->toBe(['items: missing from the restore'])->and($missing['tables']['items']['restored'])->toBeNull();

    $exact = RestoreComparison::compare($live, $live);
    expect($exact['failures'])->toBe([])->and($exact['warnings'])->toBe([])->and(collect($exact['tables'])->pluck('status')->unique()->all())->toBe(['OK']);

    // an empty table restored empty is fine; a table in the restore that is not live is ignored
    $quiet = RestoreComparison::compare(['logs' => ['rows' => 0, 'columns' => 2]], ['logs' => ['rows' => 0, 'columns' => 2], 'extra' => ['rows' => 9, 'columns' => 1]]);
    expect($quiet['failures'])->toBe([])->and(array_keys($quiet['tables']))->toBe(['logs']);
});
