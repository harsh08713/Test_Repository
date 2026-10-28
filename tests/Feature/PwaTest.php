<?php

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * The installable-app pieces (docs/pwa.md): the manifest a phone or tablet reads, the tags every page carries so it can be found, the
 * icons it names and the page shown offline. What the service worker does in a browser is checked by tests/Browser/run.mjs.
 */

beforeEach(function () {
    $this->company = makeCompany('ACME', ['name' => 'Acme Industries']);
    $this->user = makeUser(['Tele-caller'], [$this->company], attributes: ['email' => 'tanvi@example.test', 'name' => 'Tanvi Telecaller']);
});

it('serves the manifest to anyone, as a web app manifest', function () {
    $response = $this->get('/manifest.webmanifest')->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('application/manifest+json');

    $manifest = $response->json();
    expect($manifest['name'])->toBe(config('app.name'))
        ->and($manifest['display'])->toBe('standalone')
        ->and($manifest['start_url'])->toStartWith(url('/dashboard'))
        ->and($manifest['scope'])->toBe(url('/'));
});

it('says nothing about the person or the company in the manifest', function () {
    $guest = $this->get('/manifest.webmanifest')->getContent();
    $signedIn = $this->actingAs($this->user)->get('/manifest.webmanifest')->getContent();

    expect($signedIn)->toBe($guest)
        ->and($signedIn)->not->toContain('Tanvi')->not->toContain('tanvi@example.test')->not->toContain('Acme');
});

it('names icons that exist, at the sizes it says, including one a phone can crop', function () {
    $icons = $this->get('/manifest.webmanifest')->json('icons');
    $purposes = collect($icons)->pluck('purpose')->all();

    expect($purposes)->toContain('any')->toContain('maskable');

    foreach ($icons as $icon) {
        $file = public_path(parse_url($icon['src'], PHP_URL_PATH));
        expect(is_file($file))->toBeTrue("{$icon['src']} is missing");

        [$w, $h] = getimagesize($file);
        expect("{$w}x{$h}")->toBe($icon['sizes']);
    }

    // a browser offers installation only with a 192 and a 512 icon
    expect(collect($icons)->pluck('sizes')->all())->toContain('192x192')->toContain('512x512');
});

it('points every shortcut at a page that exists', function () {
    foreach ($this->get('/manifest.webmanifest')->json('shortcuts') as $shortcut) {
        $request = Request::create($shortcut['url']);

        expect(fn () => app('router')->getRoutes()->match($request))->not->toThrow(NotFoundHttpException::class);
    }
});

it('puts the app tags on the sign-in page and on a signed-in page', function () {
    foreach ([$this->get('/login'), $this->actingAs($this->user)->get('/dashboard')] as $page) {
        $page->assertOk()
            ->assertSee('viewport-fit=cover', false)
            ->assertSee('rel="manifest" href="'.route('pwa.manifest').'"', false)
            ->assertSee('rel="apple-touch-icon"', false)
            ->assertSee('name="theme-color"', false)
            ->assertSee('name="pwa-worker" content="'.asset('sw.js').'"', false);
    }
});

it('offers installation in the account menu of a signed-in page', function () {
    $this->actingAs($this->user)->get('/dashboard')->assertSee('Install app')->assertSee('Add to Home Screen', false);
});

it('ships a service worker and an offline page that stand on their own', function () {
    expect(is_file(public_path('sw.js')))->toBeTrue();

    $offline = file_get_contents(public_path('offline.html'));
    expect($offline)->toContain('You are offline')
        ->and($offline)->not->toContain('<link rel="stylesheet"')     // nothing it needs may come over the network
        ->and($offline)->not->toMatch('/<script[^>]+src=/')
        ->and($offline)->not->toContain('/build/');
});
