<?php

namespace App\Modules\Api\Services;

/**
 * What became of one queued operation, in the words an offline app needs to show it. The mapping from the HTTP answer the real endpoint gave to one of
 * these states is the *conflict policy* (docs/offline-sync.md) in code:
 *
 *  - `applied`   it ran now (a 2xx);
 *  - `replayed`  it had already run — the same Idempotency-Key — and this is the answer it gave then; nothing ran again (a 2xx marked `Idempotent-Replayed`);
 *  - `conflict`  the server's state is not what the person based the change on (a stale `expected_updated_at`, a record that moved on: 409). The person decides; the
 *                server never merges. A version conflict carries the record as it is now (`current`);
 *  - `rejected`  the server will not do it as sent (a rule, a permission, a geofence, a field, a record the person cannot see: 400/403/404/422). Retrying the same
 *                request cannot succeed: the person fixes it or drops it;
 *  - `retry`     it did not run and may be sent again as it is (too many requests, the same request still in progress, a server that is busy);
 *  - `failed`    the server broke (5xx). It may have run: send it again with the same key — a replay is safe;
 *  - `skipped`   it was not tried: an operation it depends on did not go through, or the batch stopped (see `reason`).
 */
final class SyncOutcome
{
    public const APPLIED = 'applied';

    public const REPLAYED = 'replayed';

    public const CONFLICT = 'conflict';

    public const REJECTED = 'rejected';

    public const RETRY = 'retry';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    /** Codes that mean the whole device or account is refused, not just this change: the rest of the queue is not tried. */
    private const STOPS_THE_BATCH = ['api_access_denied', 'account_inactive', 'scope_missing', 'company_not_accessible', 'no_company', 'unauthenticated'];

    /** @return list<string> */
    public static function states(): array
    {
        return [self::APPLIED, self::REPLAYED, self::CONFLICT, self::REJECTED, self::RETRY, self::FAILED, self::SKIPPED];
    }

    /**
     * @param  array<string, mixed>  $body  the decoded JSON the endpoint answered with ([] when it was not JSON)
     * @return array{status: string, http_status: int, code: string|null, message: string|null, data: mixed, current: mixed, fields: array<string, mixed>|null, retry_after: int|null, stop: bool}
     */
    public static function classify(int $httpStatus, array $body, bool $replayed = false, ?int $retryAfter = null): array
    {
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $code = isset($error['code']) ? (string) $error['code'] : null;

        $state = match (true) {
            $httpStatus >= 200 && $httpStatus < 300 => $replayed ? self::REPLAYED : self::APPLIED,
            $httpStatus === 409 && $code === 'request_in_progress' => self::RETRY,
            $httpStatus === 409 => self::CONFLICT,
            $httpStatus === 429 || $httpStatus === 503 => self::RETRY,
            $httpStatus >= 400 && $httpStatus < 500 => self::REJECTED,
            default => self::FAILED,
        };

        return [
            'status' => $state,
            'http_status' => $httpStatus,
            'code' => $code,
            'message' => isset($error['message']) ? (string) $error['message'] : null,
            'data' => $state === self::APPLIED || $state === self::REPLAYED ? ($body['data'] ?? null) : null,
            // the record as it is now, so the person can see both versions
            'current' => $state === self::CONFLICT && is_array($error['details'] ?? null) ? ($error['details']['current'] ?? null) : null,
            'fields' => is_array($error['fields'] ?? null) ? $error['fields'] : null,
            'retry_after' => $state === self::RETRY ? $retryAfter : null,
            // 401, a refused device or account, or a throttle: trying the rest of the queue now cannot go better
            'stop' => $httpStatus === 401 || $httpStatus === 429 || ($httpStatus === 403 && in_array($code, self::STOPS_THE_BATCH, true)),
        ];
    }
}
