<?php

use App\Http\UserAgent;

test('browsers and platforms are recognised from real user agent headers', function (string $header, string $browser, string $platform) {
    $agent = UserAgent::parse($header);

    expect($agent->browser)->toBe($browser)
        ->and($agent->platform)->toBe($platform);
})->with([
    'chrome on windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36', 'Chrome', 'Windows'],
    'edge on windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.2478.80', 'Edge', 'Windows'],
    'opera on windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 OPR/110.0.0.0', 'Opera', 'Windows'],
    'firefox on windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) Gecko/20100101 Firefox/125.0', 'Firefox', 'Windows'],
    'safari on macos' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15', 'Safari', 'macOS'],
    'chrome on macos' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36', 'Chrome', 'macOS'],
    'firefox on linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0', 'Firefox', 'Linux'],
    'chrome on android' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36', 'Chrome', 'Android'],
    'samsung internet on android' => ['Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36', 'Samsung Internet', 'Android'],
    'edge on android' => ['Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36 EdgA/124.0.2478.64', 'Edge', 'Android'],
    'firefox on android' => ['Mozilla/5.0 (Android 14; Mobile; rv:125.0) Gecko/125.0 Firefox/125.0', 'Firefox', 'Android'],
    'safari on ios' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1', 'Safari', 'iOS'],
    'chrome on ios' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/124.0.6367.88 Mobile/15E148 Safari/604.1', 'Chrome', 'iOS'],
    'firefox on ios' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/125.0 Mobile/15E148 Safari/605.1.15', 'Firefox', 'iOS'],
    'safari on ipados' => ['Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1', 'Safari', 'iPadOS'],
]);

test('a missing or unrecognised header reads as unknown', function (?string $header) {
    $agent = UserAgent::parse($header);

    expect($agent->browser)->toBe('Unknown')
        ->and($agent->platform)->toBe('Unknown');
})->with([
    'no header' => [null],
    'empty header' => [''],
    'command line client' => ['curl/8.7.1'],
]);

test('the label names the browser and platform in the current language', function () {
    $agent = UserAgent::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');

    app()->setLocale('en');
    expect($agent->label())->toBe('Chrome on Windows');

    app()->setLocale('ar');
    expect($agent->label())->toBe('Chrome على Windows');
});

test('the description stays english in every language', function () {
    $agent = UserAgent::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');

    app()->setLocale('ar');

    expect($agent->description())->toBe('Chrome on Windows')
        ->and(UserAgent::parse(null)->description())->toBe('Unknown on Unknown');
});

test('the fingerprint ignores version numbers but tells browsers and platforms apart', function () {
    $chrome124 = UserAgent::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
    $chrome125 = UserAgent::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.6422.60 Safari/537.36');
    $chromeOnMac = UserAgent::parse('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
    $firefox = UserAgent::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) Gecko/20100101 Firefox/125.0');

    expect($chrome124->fingerprint())->toBe($chrome125->fingerprint())
        ->toMatch('/^[0-9a-f]{64}$/')
        ->not->toBe($chromeOnMac->fingerprint())
        ->not->toBe($firefox->fingerprint());
});
