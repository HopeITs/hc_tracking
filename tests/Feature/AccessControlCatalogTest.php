<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\File;

test('the permission catalog config drives permission names', function () {
    expect(config('access-control.groups'))->toHaveKey('programs')
        ->and(config('access-control.groups.programs'))->toBe(['view', 'update']);
});

test('the lifecycle modules carry exactly their contract actions', function (string $module, array $actions) {
    expect(config("access-control.groups.{$module}"))->toBe($actions);
})->with([
    'indicator-library' => ['indicator-library', ['view', 'create', 'update', 'delete']],
    'proposals' => ['proposals', ['view', 'create', 'update', 'delete', 'manage-reviewers', 'correct-status']],
    'reports' => ['reports', ['view', 'create', 'update', 'delete', 'approve', 'mark-seen', 'reopen', 'aggregate']],
    'meal-coverage' => ['meal-coverage', ['manage']],
    'supervisions' => ['supervisions', ['manage']],
    'sectors' => ['sectors', ['view', 'manage']],
    'report-templates' => ['report-templates', ['manage']],
    'evidence-types' => ['evidence-types', ['manage']],
    'narrative-questions' => ['narrative-questions', ['manage']],
]);

test('report aggregation is never granted as a view-everything permission', function () {
    // Team Member's baseline is exactly the view-everything set.
    expect(config('access-control.role_baselines.Team Member'))
        ->toContain('reports.view')
        ->not->toContain('reports.aggregate');
});

test('there is no media-deliverables module', function () {
    expect(config('access-control.groups'))->not->toHaveKey('media-deliverables');
});

test('every module and action in the catalog has a label in english and arabic', function (string $locale) {
    foreach (config('access-control.groups') as $module => $actions) {
        expect(trans("access.modules.{$module}", [], $locale))->not->toBe("access.modules.{$module}");

        foreach ($actions as $action) {
            expect(trans("access.actions.{$action}", [], $locale))->not->toBe("access.actions.{$action}");
        }
    }
})->with(['en', 'ar']);

test('every dotted ability the code checks exists in the permission catalog', function () {
    $catalog = collect(config('access-control.groups'))
        ->flatMap(fn (array $actions, string $module): array => array_map(fn (string $action): string => "{$module}.{$action}", $actions))
        ->all();

    $pattern = '/(?:@can|@cannot|->can|->cannot|\bcan|Gate::(?:allows|denies|check|authorize))\(\s*[\'"]([a-z0-9-]+(?:\.[a-z0-9-]+)+)[\'"]/';
    $unknown = [];

    foreach (['app', 'resources/views', 'routes'] as $directory) {
        foreach (File::allFiles(base_path($directory)) as $file) {
            preg_match_all($pattern, $file->getContents(), $matches);

            foreach (array_diff($matches[1], $catalog) as $ability) {
                $unknown[] = "{$ability} ({$directory}/{$file->getRelativePathname()})";
            }
        }
    }

    expect($unknown)->toBe([]);
});

test('creating the Super Admin role grants it every permission that exists', function () {
    // The seeded catalog already has a "Super Admin" row; delete it so this
    // test observes a genuine `created` event, not a no-op findOrCreate.
    Role::query()->where('name', 'Super Admin')->delete();

    $role = Role::query()->create(['name' => 'Super Admin', 'guard_name' => 'web']);

    expect($role->fresh()->permissions->pluck('name')->all())
        ->toEqualCanonicalizing(Permission::query()->pluck('name')->all());
});

test('creating a configured role grants exactly its baseline', function () {
    Role::query()->where('name', 'Media Team')->delete();

    $role = Role::query()->create(['name' => 'Media Team', 'guard_name' => 'web']);

    expect($role->fresh()->permissions->pluck('name')->all())
        ->toEqualCanonicalizing(config('access-control.role_baselines.Media Team'));
});

test('creating an unconfigured role grants nothing automatically', function () {
    $role = Role::query()->create(['name' => 'Whatever', 'guard_name' => 'web']);

    expect($role->fresh()->permissions)->toBeEmpty();
});
