<?php

/*
 * Worker for tests/Concurrency: boots the application in its own PHP process and, at an agreed instant, sends one real
 * HTTP request through the kernel — so several processes can hit the API with the same Idempotency-Key at the same moment.
 *
 *   php tests/Support/api_worker.php <token> <idempotency-key> <json body> <startAt>
 *
 * Prints one line: "<status>|<Idempotent-Replayed or ->|<lead id, or the error code>".
 */

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(HttpKernel::class);

[$token, $key, $body] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? '{}'];
$startAt = (float) ($argv[4] ?? 0);

try {
    // Warm everything — framework, routes, database connection — so that what follows the wait is the request itself.
    $kernel->handle(Request::create('/api/v1/health', 'GET'));

    while (microtime(true) < $startAt) {
        usleep(200);
    }

    $response = $kernel->handle(Request::create('/api/v1/leads', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => "Bearer {$token}", 'HTTP_IDEMPOTENCY_KEY' => $key,
    ], $body));

    $json = json_decode((string) $response->getContent(), true) ?: [];

    echo implode('|', [$response->getStatusCode(), $response->headers->get('Idempotent-Replayed') ?? '-', $json['data']['id'] ?? $json['error']['code'] ?? '?']), PHP_EOL;
} catch (Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage(), PHP_EOL;
    exit(1);
}
