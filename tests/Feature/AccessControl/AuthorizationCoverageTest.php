<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/*
| Every public Livewire method can be called straight from the browser, so
| hiding a button is never enough: a public method that writes to the
| database must check permission itself. This test reads every Livewire
| component and fails when a public method writes without one of the
| checks below, so a newly added action that forgets its Gate::authorize()
| is caught before it ships.
|
| A method that is safe without a check (it only touches the signed-in
| user's own data, say) goes in AuthorizationCoverageExemptions with the
| reason written next to it.
*/

/**
 * Calls that change data.
 */
const AuthorizationCoverageWrites = '/->(delete|forceDelete|save|update|updateQuietly|create|createMany|insert|upsert|increment|decrement|sync|syncWithoutDetaching|attach|detach|toggle|syncPermissions|syncRoles|assignRole|removeRole|givePermissionTo|revokePermissionTo|restore)\(|::(create|insert|upsert|destroy|updateOrCreate|firstOrCreate)\(/';

/**
 * Calls that check permission.
 */
const AuthorizationCoverageChecks = '/Gate::(authorize|allows|denies|check|any|none|forUser)|\$this->authorize\w*\(|abort_(if|unless)\(|->(can|cannot|canAny)\(/';

/**
 * "file:method" => why it needs no permission check.
 *
 * @return array<string, string>
 */
function authorizationCoverageExemptions(): array
{
    return [
        'resources/views/pages/notifications/⚡settings.blade.php:updatedPopups' => 'Saves only the signed-in user\'s own popup preference.',
        'resources/views/pages/notifications/⚡settings.blade.php:toggleMute' => 'Mutes a module for the signed-in user only, and only one they may already see.',
        'resources/views/pages/settings/⚡security.blade.php:setEmailTwoFactor' => 'Changes the signed-in user\'s own sign-in codes, behind password confirmation.',
        'resources/views/pages/settings/⚡security.blade.php:signOutOtherDevices' => 'Ends only the signed-in user\'s own sessions, behind password confirmation.',
    ];
}

/**
 * The PHP class part of every Livewire component: single-file pages and
 * components, and the shared Livewire concerns.
 *
 * @return array<string, string> relative path => PHP source
 */
function livewireComponentSources(): array
{
    $sources = [];

    $views = collect(File::allFiles(resource_path('views')))
        ->filter(fn (SplFileInfo $file): bool => str_starts_with($file->getFilename(), '⚡'));

    foreach ($views as $file) {
        $contents = $file->getContents();
        $end = strpos($contents, '?>');
        $sources['resources/views/'.str_replace('\\', '/', $file->getRelativePathname())] = $end === false ? $contents : substr($contents, 0, $end);
    }

    foreach (File::allFiles(app_path('Livewire')) as $file) {
        $sources['app/Livewire/'.str_replace('\\', '/', $file->getRelativePathname())] = $file->getContents();
    }

    return $sources;
}

/**
 * Each method's name, visibility and body.
 *
 * @return list<array{name: string, visibility: string, body: string}>
 */
function livewireMethods(string $source): array
{
    preg_match_all('/^\s*(?:#\[[^\]]*\]\s*)*(public|protected|private)\s+(?:static\s+)?function\s+(\w+)\s*\(/m', $source, $matches, PREG_OFFSET_CAPTURE);

    $methods = [];
    $count = count($matches[0]);

    for ($i = 0; $i < $count; $i++) {
        $start = $matches[0][$i][1];
        $end = $i + 1 < $count ? $matches[0][$i + 1][1] : strlen($source);

        $methods[] = [
            'name' => $matches[2][$i][0],
            'visibility' => $matches[1][$i][0],
            'body' => substr($source, $start, $end - $start),
        ];
    }

    return $methods;
}

test('the scanner finds the Livewire components it guards', function () {
    $sources = livewireComponentSources();

    expect($sources)->toHaveKey('resources/views/pages/admin/⚡users.blade.php')
        ->and($sources)->toHaveKey('app/Livewire/Concerns/ManagesDonorForm.php')
        ->and(collect(livewireMethods($sources['resources/views/pages/admin/⚡users.blade.php']))->pluck('name'))
        ->toContain('saveRolePermissions');
});

test('every public Livewire method that writes data checks permission', function () {
    $unchecked = [];

    foreach (livewireComponentSources() as $path => $source) {
        foreach (livewireMethods($source) as $method) {
            $key = "{$path}:{$method['name']}";

            if ($method['visibility'] !== 'public'
                || array_key_exists($key, authorizationCoverageExemptions())
                || ! preg_match(AuthorizationCoverageWrites, $method['body'])
                || preg_match(AuthorizationCoverageChecks, $method['body'])) {
                continue;
            }

            $unchecked[] = $key;
        }
    }

    expect($unchecked)->toBe([], "These public Livewire methods write data without a permission check.\nAdd Gate::authorize(...) to each, or list it in authorizationCoverageExemptions() with the reason:\n".implode("\n", $unchecked));
});

test('every exemption still points at a real method', function () {
    $sources = livewireComponentSources();

    foreach (array_keys(authorizationCoverageExemptions()) as $key) {
        [$path, $name] = explode(':', $key);

        expect($sources)->toHaveKey($path)
            ->and(collect(livewireMethods($sources[$path]))->pluck('name'))->toContain($name);
    }
});
