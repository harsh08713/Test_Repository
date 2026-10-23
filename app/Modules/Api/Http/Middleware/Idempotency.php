<?php

namespace App\Modules\Api\Http\Middleware;

use App\Models\User;
use App\Modules\Api\Support\ApiException;
use App\Modules\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Makes a create (or any change) safe to send twice. The app sends `Idempotency-Key: <a fresh UUID per action>`; the first
 * request runs and its successful answer is remembered under (user, key); a repeat — the network dropped the reply, or the
 * offline queue replayed — gets that same answer back, marked `Idempotent-Replayed: true`, and nothing runs again.
 *
 *  - `idempotent:required` refuses a request without a key (creates); plain `idempotent` uses a key when one is sent.
 *  - The same key with a **different** request (other path, body or company) is an error, not a silent replay.
 *  - Two requests with one key arriving together: the database's unique key lets exactly one in; the other is told to wait
 *    (409 request_in_progress, Retry-After) and, once the first finishes, gets its answer.
 *  - Only a **successful** answer is kept. Anything else (a refusal, a validation error, a crash) releases the key, so the
 *    person can fix the problem and send the same action again.
 *  - A request that started but never finished (the server died) is presumed dead after `idempotency_stale_seconds` and the
 *    key may then be taken over. Keys expire after `idempotency_ttl_hours` (`api:prune` removes them).
 */
class Idempotency
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $key = $request->header('Idempotency-Key');

        if ($key === null || $key === '') {
            if ($mode === 'required') {
                throw ApiException::idempotencyKeyRequired();
            }

            return $next($request);
        }

        if (preg_match('/^[A-Za-z0-9_\-:.]{8,80}$/', $key) !== 1) {
            throw ApiException::idempotencyKeyInvalid();
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $companyId = $this->tenant->activeCompanyId();
        $fingerprint = $this->fingerprint($request, $companyId);

        $replay = $this->claim((int) $user->getKey(), $companyId, $key, $fingerprint, $request);

        if ($replay !== null) {
            return $replay;
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->release((int) $user->getKey(), $key);

            throw $e;
        }

        if ($response->isSuccessful() && $response instanceof JsonResponse) {
            DB::table('api_idempotency_keys')->where('user_id', $user->getKey())->where('idempotency_key', $key)->update([
                'state' => 'completed', 'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(), 'completed_at' => now(),
            ]);
        } else {
            $this->release((int) $user->getKey(), $key);
        }

        return $response;
    }

    /**
     * Take the key, or find out what its holder did. Null means "go ahead: this request owns the key now".
     */
    private function claim(int $userId, ?int $companyId, string $key, string $fingerprint, Request $request): ?Response
    {
        $ttl = (int) config('formezy.api.idempotency_ttl_hours', 48);
        $stale = (int) config('formezy.api.idempotency_stale_seconds', 60);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            try {
                DB::table('api_idempotency_keys')->insert([
                    'user_id' => $userId, 'company_id' => $companyId, 'idempotency_key' => $key, 'fingerprint' => $fingerprint,
                    'method' => $request->method(), 'path' => mb_substr($request->path(), 0, 160), 'state' => 'started',
                    'created_at' => now(), 'expires_at' => now()->addHours($ttl),
                ]);

                return null;
            } catch (UniqueConstraintViolationException) {
                $row = DB::table('api_idempotency_keys')->where('user_id', $userId)->where('idempotency_key', $key)->first();

                if (! $row instanceof stdClass) {
                    continue;   // released between our insert and our read: try to take it again
                }

                if (now()->gte($row->expires_at)) {
                    DB::table('api_idempotency_keys')->where('id', $row->id)->where('expires_at', $row->expires_at)->delete();

                    continue;
                }

                if (! hash_equals((string) $row->fingerprint, $fingerprint)) {
                    throw ApiException::idempotencyKeyReused();
                }

                if ($row->state === 'completed') {
                    return $this->replay($row);
                }

                // Started and not finished. Is the request that started it still alive?
                if (now()->diffInSeconds($row->created_at, true) >= $stale) {
                    $took = DB::table('api_idempotency_keys')->where('id', $row->id)->where('state', 'started')->where('created_at', $row->created_at)
                        ->update(['created_at' => now()]);

                    if ($took === 1) {
                        return null;
                    }

                    continue;
                }

                throw ApiException::requestInProgress();
            }
        }

        throw ApiException::requestInProgress();
    }

    private function replay(stdClass $row): JsonResponse
    {
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $row->response_body, true) ?: [];

        return response()->json($body, (int) $row->response_status, ['Idempotent-Replayed' => 'true']);
    }

    private function release(int $userId, string $key): void
    {
        DB::table('api_idempotency_keys')->where('user_id', $userId)->where('idempotency_key', $key)->where('state', 'started')->delete();
    }

    /** The same key must mean the same request: method, path, company and every input, in a fixed order. */
    private function fingerprint(Request $request, ?int $companyId): string
    {
        return hash('sha256', implode('|', [$request->method(), $request->path(), (string) $companyId, json_encode($this->sorted($request->all()))]));
    }

    private function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map($this->sorted(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
