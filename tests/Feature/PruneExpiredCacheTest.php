<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->freezeTime();

    $prefix = config('cache.prefix');
    $expired = now()->subMinute()->getTimestamp();
    $live = now()->addMinute()->getTimestamp();

    DB::table('cache')->insert([
        ['key' => $prefix.'expired-limit', 'value' => 'i:1;', 'expiration' => $expired],
        ['key' => $prefix.'live-limit', 'value' => 'i:1;', 'expiration' => $live],
        // Another application sharing the table: never touched.
        ['key' => 'other_app_expired', 'value' => 'i:1;', 'expiration' => $expired],
    ]);

    DB::table('cache_locks')->insert([
        ['key' => $prefix.'expired-lock', 'owner' => 'a', 'expiration' => $expired],
        ['key' => $prefix.'live-lock', 'owner' => 'b', 'expiration' => $live],
    ]);

    $this->remainingKeys = fn (): array => [
        ...DB::table('cache')->orderBy('key')->pluck('key')->all(),
        ...DB::table('cache_locks')->orderBy('key')->pluck('key')->all(),
    ];
});

test('it deletes this app\'s expired cache rows and locks, and nothing else', function () {
    config(['cache.default' => 'database']);
    $prefix = config('cache.prefix');

    $this->artisan('cache:prune-expired')
        ->expectsOutputToContain('Deleted 1 expired cache row(s) and 1 expired lock(s).')
        ->assertSuccessful();

    expect(($this->remainingKeys)())->toEqualCanonicalizing([
        $prefix.'live-limit', 'other_app_expired', $prefix.'live-lock',
    ]);
});

test('a dry run reports the expired rows and deletes nothing', function () {
    config(['cache.default' => 'database']);

    $this->artisan('cache:prune-expired', ['--dry-run' => true])
        ->expectsOutputToContain('1 expired cache row(s) and 1 expired lock(s) would be deleted.')
        ->assertSuccessful();

    expect(($this->remainingKeys)())->toHaveCount(5);
});

test('it leaves the table alone when the cache is not kept in the database', function () {
    $this->artisan('cache:prune-expired')->assertSuccessful();

    expect(($this->remainingKeys)())->toHaveCount(5);
});
