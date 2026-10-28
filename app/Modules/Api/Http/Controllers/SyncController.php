<?php

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Services\SyncRunner;
use App\Modules\Api\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/sync` — an offline app sends the changes it queued, in order, and learns what became of each one.
 *
 * The call itself only checks the *shape* of the queue; every change is run by SyncRunner through the real endpoint it names, so no rule lives here. The answer
 * is always 200 when the queue was readable: how each change fared is in its own entry (`applied`, `replayed`, `conflict`, `rejected`, `retry`, `failed`,
 * `skipped`) — see docs/offline-sync.md for what each means and what the app should do with it.
 */
class SyncController extends ApiController
{
    public function __invoke(Request $request, SyncRunner $runner): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'operations' => ['required', 'array', 'min:1', 'max:'.SyncRunner::MAX_OPERATIONS],
            'operations.*' => ['array:id,method,path,body,ref'],
            'operations.*.id' => ['required', 'string', 'regex:/^[A-Za-z0-9_\-:.]{8,80}$/', 'distinct'],
            'operations.*.method' => ['required', 'string', Rule::in(['POST', 'PATCH', 'DELETE'])],
            'operations.*.path' => ['required', 'string', 'max:160', 'regex:/^\/[A-Za-z0-9_\-\/@.]*$/', 'not_regex:/\.\./'],
            'operations.*.body' => ['nullable', 'array'],
            'operations.*.ref' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,40}$/', 'distinct'],
        ], [
            'operations.*.id.regex' => 'An operation id is 8 to 80 characters: letters, digits and - _ : . — it is also its Idempotency-Key, so make it a fresh UUID per queued change.',
            'operations.*.path.regex' => 'Give the path under /api/v1 (for example /leads/12/followups), with no query string and no host.',
            'operations.*.path.not_regex' => 'A path may not contain “..”.',
        ])->validate();

        /** @var list<array{id: string, method: string, path: string, body: array<string, mixed>|null, ref: string|null}> $operations */
        $operations = array_map(fn (array $operation) => [
            'id' => (string) $operation['id'], 'method' => (string) $operation['method'], 'path' => (string) $operation['path'],
            'body' => isset($operation['body']) && is_array($operation['body']) ? $operation['body'] : null,
            'ref' => isset($operation['ref']) && $operation['ref'] !== '' ? (string) $operation['ref'] : null,
        ], array_values($data['operations']));

        return ApiResponse::data($runner->run($request, $operations));
    }
}
