<?php

use App\Modules\Api\Services\SyncOutcome;

it('names what became of a queued change by what the real endpoint answered', function (int $http, array $body, bool $replayed, string $state, bool $stop) {
    $outcome = SyncOutcome::classify($http, $body, $replayed, 7);

    expect($outcome['status'])->toBe($state)->and($outcome['http_status'])->toBe($http)->and($outcome['stop'])->toBe($stop);
})->with([
    'created' => [201, ['data' => ['id' => 5]], false, SyncOutcome::APPLIED, false],
    'changed' => [200, ['data' => ['id' => 5]], false, SyncOutcome::APPLIED, false],
    'sent again' => [201, ['data' => ['id' => 5]], true, SyncOutcome::REPLAYED, false],
    'a stale edit' => [409, ['error' => ['code' => 'version_conflict', 'message' => 'x', 'details' => ['current' => ['id' => 5]]]], false, SyncOutcome::CONFLICT, false],
    'a record that moved on' => [409, ['error' => ['code' => 'state_conflict', 'message' => 'x']], false, SyncOutcome::CONFLICT, false],
    'still being processed' => [409, ['error' => ['code' => 'request_in_progress', 'message' => 'x']], false, SyncOutcome::RETRY, false],
    'a rule says no' => [422, ['error' => ['code' => 'validation_failed', 'message' => 'x', 'fields' => ['name' => ['Required.']]]], false, SyncOutcome::REJECTED, false],
    'not found' => [404, ['error' => ['code' => 'not_found', 'message' => 'x']], false, SyncOutcome::REJECTED, false],
    'not allowed' => [403, ['error' => ['code' => 'forbidden', 'message' => 'x']], false, SyncOutcome::REJECTED, false],
    'device refused' => [403, ['error' => ['code' => 'scope_missing', 'message' => 'x']], false, SyncOutcome::REJECTED, true],
    'account switched off' => [403, ['error' => ['code' => 'account_inactive', 'message' => 'x']], false, SyncOutcome::REJECTED, true],
    'token gone' => [401, ['error' => ['code' => 'unauthenticated', 'message' => 'x']], false, SyncOutcome::REJECTED, true],
    'slow down' => [429, ['error' => ['code' => 'too_many_requests', 'message' => 'x']], false, SyncOutcome::RETRY, true],
    'busy' => [503, [], false, SyncOutcome::RETRY, false],
    'broke' => [500, [], false, SyncOutcome::FAILED, false],
    'bad gateway' => [502, [], false, SyncOutcome::FAILED, false],
]);

it('hands back the answer only when it ran, the record as it is only for a version conflict, and the wait only when it should wait', function () {
    $ran = SyncOutcome::classify(201, ['data' => ['id' => 9]]);
    $conflict = SyncOutcome::classify(409, ['error' => ['code' => 'version_conflict', 'message' => 'x', 'details' => ['current' => ['id' => 9, 'name' => 'Now']]]], false, 3);
    $rejected = SyncOutcome::classify(422, ['error' => ['code' => 'validation_failed', 'message' => 'x', 'fields' => ['name' => ['Required.']]], 'data' => ['id' => 1]], false, 3);
    $retry = SyncOutcome::classify(429, [], false, 12);

    expect($ran['data'])->toBe(['id' => 9])->and($ran['current'])->toBeNull()->and($ran['retry_after'])->toBeNull()
        ->and($conflict['data'])->toBeNull()->and($conflict['current'])->toBe(['id' => 9, 'name' => 'Now'])->and($conflict['retry_after'])->toBeNull()
        ->and($rejected['data'])->toBeNull()->and($rejected['fields'])->toBe(['name' => ['Required.']])->and($rejected['code'])->toBe('validation_failed')
        ->and($retry['retry_after'])->toBe(12)->and($retry['data'])->toBeNull();
});

it('has a name for every state an app has to show', function () {
    expect(SyncOutcome::states())->toBe(['applied', 'replayed', 'conflict', 'rejected', 'retry', 'failed', 'skipped']);
});
