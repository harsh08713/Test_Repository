<?php

/*
 * The headers every response carries, on the web and on the API. HSTS only on an HTTPS request in production. A header a response set itself is left alone.
 */

it('sends the safety headers on every answer: pages, the API, errors and unknown paths', function () {
    foreach (['/login', '/api/v1/health', '/api/v1/no-such-thing', '/no-such-page'] as $url) {
        $response = $this->get($url);

        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
            ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
            ->and($response->headers->get('Permissions-Policy'))->toContain('geolocation=(self)')->toContain('camera=()')->toContain('microphone=()');
    }
});

it('asks for HTTPS only on an HTTPS request in production', function () {
    $plain = $this->get('/login');
    expect($plain->headers->has('Strict-Transport-Security'))->toBeFalse();   // the test server is plain HTTP in the local environment

    $this->app['env'] = 'production';
    $secure = $this->get('https://localhost/login');
    expect($secure->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000; includeSubDomains');

    $http = $this->get('http://localhost/login');
    expect($http->headers->has('Strict-Transport-Security'))->toBeFalse();   // production, but not secure: no HSTS
});

it('has no Content-Security-Policy, on purpose (Livewire and Alpine evaluate expressions in the page)', function () {
    expect($this->get('/login')->headers->has('Content-Security-Policy'))->toBeFalse();
});
