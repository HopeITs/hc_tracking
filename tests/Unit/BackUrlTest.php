<?php

use App\Support\BackUrl;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Make the given request the current one for the rest of the test.
 *
 * @param  array<string, string>  $headers
 */
function currentRequest(string $uri, array $headers = []): void
{
    $request = Request::create($uri, 'GET');
    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }
    app()->instance('request', $request);
}

beforeEach(function (): void {
    config(['app.url' => 'http://localhost']);
});

test('an in-app referer wins over the fallback', function (): void {
    currentRequest('http://localhost/proposals/one', ['referer' => 'http://localhost/projects/alpha?tab=plan']);

    expect(BackUrl::for('/proposals'))->toBe('http://localhost/projects/alpha?tab=plan');
});

test('a referer on another host falls back', function (): void {
    currentRequest('http://localhost/proposals/one', ['referer' => 'https://evil.example/projects/alpha']);

    expect(BackUrl::for('/proposals'))->toBe('/proposals');
});

test('no referer falls back', function (): void {
    currentRequest('http://localhost/proposals/one');

    expect(BackUrl::for('/proposals'))->toBe('/proposals');
});

test('a referer equal to the current page falls back', function (): void {
    currentRequest('http://localhost/proposals/one?tab=plan', ['referer' => 'http://localhost/proposals/one']);

    expect(BackUrl::for('/proposals'))->toBe('/proposals');
});

test('a non-http referer falls back', function (): void {
    currentRequest('http://localhost/proposals/one', ['referer' => 'javascript:alert(1)']);

    expect(BackUrl::for('/proposals'))->toBe('/proposals');
});
