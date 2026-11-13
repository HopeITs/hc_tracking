<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

afterEach(function () {
    File::deleteDirectory(storage_path('framework/cache/pretend-2099-01-01'));
});

test('a counter saved under a pretend date never reaches the shared cache folder', function () {
    $sharedPath = config('cache.stores.file.path');
    $provider = app()->getProvider(AppServiceProvider::class);

    (fn () => $this->keepPretendTodayCacheApart('2099-01-01'))->call($provider);
    Cache::forgetDriver('file');

    $this->travelTo('2099-01-01 10:00:00');
    Cache::store('file')->put('pretend-today-counter', 180, 60);
    $this->travelBack();

    expect(config('cache.stores.file.path'))->toBe(storage_path('framework/cache/pretend-2099-01-01'))
        ->and(Cache::store('file')->get('pretend-today-counter'))->toBe(180);

    config(['cache.stores.file.path' => $sharedPath]);
    Cache::forgetDriver('file');

    expect(Cache::store('file')->get('pretend-today-counter'))->toBeNull();
});
