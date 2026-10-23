<?php

use App\Modules\CRM\Enums\ViolationReason;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

/*
 * The API's documentation must not drift from the API. docs/api/openapi.yaml lists every operation; this test holds it
 * equal to the routes that are really registered, and docs/api.md equal to the error codes the code can really send.
 */

beforeEach(function () {
    $this->spec = Yaml::parseFile(base_path('docs/api/openapi.yaml'));

    $this->routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route) => str_starts_with($route->uri(), 'api/v1'))
        ->flatMap(fn (LaravelRoute $route) => collect($route->methods())->reject(fn ($m) => in_array($m, ['HEAD', 'OPTIONS'], true))
            ->map(fn ($method) => ['method' => strtolower($method), 'path' => '/'.substr($route->uri(), strlen('api/v1/')), 'route' => $route]))
        ->values();
});

it('documents every API route, and only those', function () {
    $documented = collect($this->spec['paths'])->flatMap(fn (array $item, string $path) => collect($item)
        ->keys()->filter(fn ($key) => in_array($key, ['get', 'post', 'put', 'patch', 'delete'], true))
        ->map(fn ($method) => strtoupper($method).' '.$path))->sort()->values()->all();

    $registered = $this->routes->map(fn (array $r) => strtoupper($r['method']).' '.$r['path'])->sort()->values()->all();

    expect($registered)->not->toBeEmpty()->and($documented)->toBe($registered);
});

it('names each operation after its route and describes how it is authorised', function () {
    foreach ($this->routes as $r) {
        $operation = $this->spec['paths'][$r['path']][$r['method']];
        $name = $r['route']->getName();
        $open = in_array($name, ['api.v1.health', 'api.v1.auth.login'], true);

        expect($operation['operationId'])->toBe($name)
            ->and($operation['summary'])->not->toBeEmpty()
            ->and($operation['responses'])->not->toBeEmpty()
            ->and(array_key_exists('security', $operation) && $operation['security'] === [])->toBe($open, "{$name}: only health and login are open");
    }

    // everything else is behind the device token, which the spec declares once for the whole API
    expect($this->spec['security'])->toBe([['deviceToken' => []]]);
});

it('says which creates need an Idempotency-Key, exactly as the routes demand it', function () {
    foreach ($this->routes as $r) {
        $middleware = collect($r['route']->gatherMiddleware())->filter(fn ($m) => is_string($m));
        $operation = $this->spec['paths'][$r['path']][$r['method']];
        $refs = collect($operation['parameters'] ?? [])->pluck('$ref')->filter()->map(fn ($ref) => basename($ref));

        $required = $middleware->contains('idempotent:required');
        $optional = $middleware->contains('idempotent');

        expect($refs->contains('IdempotencyKey'))->toBe($required, "{$r['route']->getName()}: required key documented")
            ->and($refs->contains('IdempotencyKeyOptional'))->toBe($optional, "{$r['route']->getName()}: optional key documented");
    }
});

it('documents each path parameter under the name the route uses', function () {
    foreach ($this->routes as $r) {
        preg_match_all('/\{(\w+)\}/', $r['path'], $m);
        $item = $this->spec['paths'][$r['path']];
        $declared = collect([...($item['parameters'] ?? []), ...($item[$r['method']]['parameters'] ?? [])])->where('in', 'path')->pluck('name')->sort()->values()->all();

        expect($declared)->toBe(collect($m[1])->sort()->values()->all(), $r['route']->getName());
    }
});

it('has every $ref pointing at something that exists', function () {
    $walk = function (mixed $node) use (&$walk, &$refs) {
        if (is_array($node)) {
            foreach ($node as $key => $value) {
                $key === '$ref' && is_string($value) ? $refs[] = $value : $walk($value);
            }
        }
    };
    $refs = [];
    $walk($this->spec);

    expect($refs)->not->toBeEmpty();

    foreach (array_unique($refs) as $ref) {
        $target = $this->spec;
        foreach (explode('/', ltrim($ref, '#/')) as $part) {
            expect(is_array($target) && array_key_exists($part, $target))->toBeTrue("dangling reference {$ref}");
            $target = $target[$part];
        }
    }
});

it('lists every error code the API can answer with in the guide', function () {
    $guide = (string) file_get_contents(base_path('docs/api.md'));

    $sources = collect(['Support/ApiException.php', 'Support/ApiExceptionRenderer.php', 'Http/Middleware/RequireReadableJson.php', 'Http/Controllers/LeadController.php'])
        ->map(fn (string $file) => (string) file_get_contents(app_path("Modules/Api/{$file}")))->implode("\n");

    preg_match_all("/(?:new self\\(|ApiResponse::error\\(|new ApiException\\(|conflict\\()'([a-z_]+)'/", $sources, $found);

    $codes = collect($found[1])->merge(array_map(fn (ViolationReason $r) => $r->value, ViolationReason::cases()))->unique()->sort()->values();

    expect($codes->count())->toBeGreaterThan(20);

    foreach ($codes as $code) {
        expect(str_contains($guide, "`{$code}`"))->toBeTrue("docs/api.md does not mention the error code {$code}");
    }
});
