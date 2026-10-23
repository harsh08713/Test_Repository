<?php

/*
 * Worker for tests/Concurrency: boots the application in its own PHP process and, at an agreed instant, posts one lead to a
 * provider's webhook address through the real HTTP kernel — so several processes can deliver at the same moment. The queue is
 * synchronous, so the lead is made inside the request, as it is in a small installation.
 *
 *   php tests/Support/webhook_worker.php <provider> <public key> <json body> <startAt>
 *
 * Prints one line: "<status>|<new>|<already known>" or "ERROR: …".
 */

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(HttpKernel::class);

[$provider, $key, $body] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? '{}'];
$startAt = (float) ($argv[4] ?? 0);

try {
    // warm the framework, the routes and the database connection, then wait for the shared start time
    $kernel->handle(Request::create('/api/v1/health', 'GET'));

    while (microtime(true) < $startAt) {
        usleep(200);
    }

    $response = $kernel->handle(Request::create("/api/webhooks/{$provider}/{$key}", 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], $body));

    $json = json_decode((string) $response->getContent(), true) ?: [];

    echo implode('|', [$response->getStatusCode(), $json['data']['new'] ?? ($json['error']['code'] ?? '?'), $json['data']['already_known'] ?? '-']), PHP_EOL;
} catch (Throwable $e) {
    echo 'ERROR: '.get_class($e).': '.$e->getMessage(), PHP_EOL;
    exit(1);
}
