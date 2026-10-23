<?php

use Illuminate\Support\Facades\File;
use Livewire\Component;

/*
 * Rules a Livewire component has to keep that no server-side test of its behaviour would catch: they only bite in a real
 * browser. Each was learned from a screen that passed every test and did not work.
 */

/** Every Livewire component of the application. */
function livewireComponents(): array
{
    $classes = [];

    foreach (File::allFiles(app_path('Modules')) as $file) {
        $path = str_replace('\\', '/', $file->getPathname());

        if ($file->getExtension() !== 'php' || ! str_contains($path, '/Livewire/')) {
            continue;
        }

        $class = 'App\\'.str_replace('/', '\\', substr($path, strlen(str_replace('\\', '/', app_path())) + 1, -4));

        if (class_exists($class) && is_subclass_of($class, Component::class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

it('never names an action after something Livewire reserves in the browser', function () {
    // In the browser, `wire:click="upload"` does not reach a PHP method called upload(): Livewire's own `$wire.upload`
    // (and `set`, `get`, `call`, `dispatch`, `watch`…) is found first and run instead. The list is read from the
    // installed library, so an upgrade that reserves another name is noticed here.
    $script = (string) file_get_contents(base_path('vendor/livewire/livewire/dist/livewire.js'));

    expect(preg_match('/var aliases = \{([^}]+)\}/', $script, $block))->toBe(1);
    preg_match_all('/"([A-Za-z]+)":\s*"\$/', $block[1], $found);
    $reserved = $found[1];

    expect($reserved)->toContain('upload', 'set', 'get', 'call', 'dispatch', 'watch', 'errors', 'on');

    $components = livewireComponents();
    $offenders = [];

    foreach ($components as $class) {
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // only what the application declares (in the class, a parent of ours or a trait of ours): not Livewire's own API
            if (str_starts_with($method->getDeclaringClass()->getName(), 'App\\') && in_array($method->getName(), $reserved, true)) {
                $offenders[] = $method->getDeclaringClass()->getName().'::'.$method->getName().'()';
            }
        }
    }

    expect(count($components))->toBeGreaterThan(40)                 // the scan really found the screens
        ->and(array_values(array_unique($offenders)))->toBe([]);
});

it('never names an action after a function the browser already has on `window`', function () {
    // `wire:click="open(5)"` is evaluated in the page, where `open` is window.open: the click then opens a (blocked) pop-up and
    // the PHP method never runs. Found in Phase 9 when a "view this delivery" button did nothing in a real browser while its
    // Pest test passed. Name the action for what it does to the record: inspect(), closeInspector(), openDialog()…
    $window = [
        'open', 'close', 'print', 'stop', 'find', 'alert', 'confirm', 'prompt', 'focus', 'blur', 'scroll', 'scrollTo', 'scrollBy',
        'moveTo', 'moveBy', 'resizeTo', 'resizeBy', 'fetch', 'postMessage', 'atob', 'btoa', 'eval', 'escape', 'unescape',
        'setTimeout', 'setInterval', 'clearTimeout', 'clearInterval', 'requestAnimationFrame', 'getSelection', 'matchMedia', 'queueMicrotask',
    ];

    $offenders = [];

    foreach (livewireComponents() as $class) {
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getDeclaringClass()->getName(), 'App\\') && in_array($method->getName(), $window, true)) {
                $offenders[] = $method->getDeclaringClass()->getName().'::'.$method->getName().'()';
            }
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([]);
});

it('never names a page component Index', function () {
    // Livewire 4 treats a class called Index as its folder's default component and cannot resolve it back from its name.
    $named = array_values(array_filter(livewireComponents(), fn (string $class) => class_basename($class) === 'Index'));

    expect($named)->toBe([]);
});
