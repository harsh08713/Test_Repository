<?php

namespace App\Modules\Api\Services;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Runs an offline app's queue of changes, in order, **through the real endpoints**.
 *
 * Each operation becomes an ordinary request to the route it names — same token, same company, its own `Idempotency-Key` — and is dispatched through the
 * router, so every rule of the real call applies unchanged: the device's scope, the throttle, the idempotency key, validation, the policies, the
 * geofence, the approval rules. Nothing about a queued change is trusted more because it was queued; this class owns no business rule, it only carries
 * requests and describes how each one ended (`SyncOutcome`).
 *
 *  - Only a change that is safe to replay can be queued: a POST, PATCH or DELETE on a `/api/v1` route that honours an Idempotency-Key. Signing in or out, reads
 *    and `/sync` itself are not.
 *  - An operation may name a record made earlier in the same queue: `@lead-1` in a path becomes the id the operation with `ref: "lead-1"` was given. If that
 *    operation did not go through, the one that needs it is skipped — never sent with a guess.
 *  - Operations that do not depend on each other do not hold each other up: a rejected or conflicting change does not stop the rest. A refused device or
 *    account, an expired token or a throttle does (everything after it is `skipped`), because trying again now cannot go better.
 *
 * @phpstan-type Operation array{id: string, method: string, path: string, body: array<string, mixed>|null, ref: string|null}
 */
final class SyncRunner
{
    public const MAX_OPERATIONS = 25;

    public function __construct(private readonly Router $router) {}

    /**
     * @param  list<array{id: string, method: string, path: string, body: array<string, mixed>|null, ref: string|null}>  $operations
     * @return array{results: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function run(Request $parent, array $operations): array
    {
        $results = [];
        $made = [];         // ref => the id the operation that made it was given
        $failedRefs = [];   // refs whose operation did not go through
        $stopped = null;    // why the rest of the queue is not being tried

        foreach ($operations as $operation) {
            $result = $this->one($parent, $operation, $made, $failedRefs, $stopped);

            $results[] = ['id' => $operation['id'], 'ref' => $operation['ref'], ...$result];
        }

        $summary = array_fill_keys(SyncOutcome::states(), 0);
        foreach ($results as $result) {
            $summary[$result['status']]++;
        }

        return ['results' => $results, 'summary' => $summary];
    }

    /**
     * @param  array{id: string, method: string, path: string, body: array<string, mixed>|null, ref: string|null}  $operation
     * @param  array<string, string>  $made
     * @param  array<string, true>  $failedRefs
     * @return array<string, mixed>
     */
    private function one(Request $parent, array $operation, array &$made, array &$failedRefs, ?string &$stopped): array
    {
        $skip = function (string $reason, string $message) use ($operation, &$failedRefs): array {
            $operation['ref'] === null || $failedRefs[$operation['ref']] = true;

            return $this->plain(SyncOutcome::SKIPPED, $reason, $message);
        };

        if ($stopped !== null) {
            return $skip('batch_stopped', $stopped);
        }

        // "@lead-1" in the path: the record an earlier operation made
        $path = $operation['path'];
        foreach ($this->refsIn($path) as $name) {
            if (isset($failedRefs[$name])) {
                return $skip('depends_on_failed', "It needs “{$name}”, which was not made. Send it again after that one goes through.");
            }

            if (! isset($made[$name])) {
                $operation['ref'] === null || $failedRefs[$operation['ref']] = true;

                return $this->plain(SyncOutcome::REJECTED, 'unknown_ref', "“@{$name}” is not the ref of an earlier operation in this queue.");
            }

            $path = str_replace("@{$name}", $made[$name], $path);
        }

        $request = $this->subRequest($parent, $operation, $path);
        $route = $this->syncable($request);

        if ($route === null) {
            $operation['ref'] === null || $failedRefs[$operation['ref']] = true;

            return $this->plain(SyncOutcome::REJECTED, 'not_syncable', 'That call cannot be queued offline: only a change the app may send again safely (it takes an Idempotency-Key) can.');
        }

        $original = app('request');
        app()->instance('request', $request);   // as the HTTP kernel does: the request helper, the user resolver and the route resolver follow it

        try {
            $response = $this->router->dispatch($request);
            $body = json_decode((string) $response->getContent(), true);
            $outcome = SyncOutcome::classify(
                $response->getStatusCode(), is_array($body) ? $body : [], $response->headers->get('Idempotent-Replayed') === 'true',
                $response->headers->has('Retry-After') ? (int) $response->headers->get('Retry-After') : null,
            );
        } catch (HttpExceptionInterface $e) {
            $outcome = SyncOutcome::classify($e->getStatusCode(), []);
        } catch (Throwable $e) {
            report($e);
            $outcome = SyncOutcome::classify(500, []);
        } finally {
            app()->instance('request', $original);
        }

        $stop = $outcome['stop'];
        unset($outcome['stop']);

        if ($stop) {
            $stopped = $outcome['http_status'] === 429
                ? 'The server is asking the app to slow down. Send the rest of the queue again in a moment.'
                : 'The device or account was refused, so the rest of the queue was not tried.';
        }

        $ok = in_array($outcome['status'], [SyncOutcome::APPLIED, SyncOutcome::REPLAYED], true);

        if ($ok && $operation['ref'] !== null && is_array($outcome['data']) && isset($outcome['data']['id']) && is_scalar($outcome['data']['id'])) {
            $made[$operation['ref']] = (string) $outcome['data']['id'];
        } elseif (! $ok && $operation['ref'] !== null) {
            $failedRefs[$operation['ref']] = true;
        }

        return $outcome;
    }

    /** @param  array{id: string, method: string, path: string, body: array<string, mixed>|null, ref: string|null}  $operation */
    private function subRequest(Request $parent, array $operation, string $path): Request
    {
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $operation['id']];

        foreach (['Authorization' => 'HTTP_AUTHORIZATION', 'X-Company-Id' => 'HTTP_X_COMPANY_ID', 'X-App-Version' => 'HTTP_X_APP_VERSION', 'User-Agent' => 'HTTP_USER_AGENT'] as $header => $key) {
            $value = $parent->header($header);
            $value === null || $server[$key] = $value;
        }

        $address = $parent->server('REMOTE_ADDR');
        is_string($address) && $server['REMOTE_ADDR'] = $address;

        return Request::create('/api/v1'.$path, $operation['method'], [], [], [], $server, $operation['body'] === null ? null : (string) json_encode($operation['body']));
    }

    /** The route this request would run, if — and only if — it is an API change the app may queue. */
    private function syncable(Request $request): ?Route
    {
        try {
            $route = $this->router->getRoutes()->match($request);
        } catch (Throwable) {
            return null;
        }

        $name = (string) $route->getName();
        $honoursKey = collect($route->gatherMiddleware())->contains(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'idempotent'));

        return str_starts_with($name, 'api.v1.') && $name !== 'api.v1.sync' && ! str_starts_with($name, 'api.v1.auth.') && $honoursKey ? $route : null;
    }

    /** @return list<string> */
    private function refsIn(string $path): array
    {
        preg_match_all('/@([A-Za-z0-9_-]{1,40})/', $path, $found);

        return array_values(array_unique($found[1]));
    }

    /** @return array<string, mixed> */
    private function plain(string $status, string $code, string $message): array
    {
        return ['status' => $status, 'http_status' => null, 'code' => $code, 'message' => $message, 'data' => null, 'current' => null, 'fields' => null, 'retry_after' => null];
    }
}
