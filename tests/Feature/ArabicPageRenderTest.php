<?php

/**
 * T26 checklist: every new page renders right to left in Arabic with its
 * Arabic title and one Arabic enum label, and left to right in English.
 *
 * @return array<string, array{0: string, 1: string, 2: string, 3: string}> route, role, title key, enum label key
 */
dataset('translated pages', [
    'indicator library' => ['indicator-library.index', 'MEAL', 'titles.indicator_library', 'enums.indicator_measure.percentage'],
    'department tasks' => ['department-tasks.index', 'Team Member', 'department_tasks.page_title', 'enums.delivery_state.requested'],
    'city directory' => ['admin.cities.index', 'General Manager', 'titles.city_directory', 'cities.cities_covered'],
    'sectors' => ['admin.sectors.index', 'MEAL', 'titles.sectors', 'sectors.add_sector'],
]);

test('a new page renders right to left in arabic with an arabic title and label', function (string $route, string $role, string $titleKey, string $labelKey) {
    $user = userWithRole($role);
    $user->forceFill(['locale' => 'ar'])->save();

    $this->actingAs($user)
        ->get(route($route))
        ->assertOk()
        ->assertSee('<html lang="ar" dir="rtl"', false)
        ->assertSee(trans($titleKey, [], 'ar'))
        ->assertSee(trans($labelKey, [], 'ar'));
})->with('translated pages');

test('the same page renders left to right in english', function (string $route, string $role, string $titleKey, string $labelKey) {
    $user = userWithRole($role);
    $user->forceFill(['locale' => 'en'])->save();

    $this->actingAs($user)
        ->get(route($route))
        ->assertOk()
        ->assertSee('<html lang="en" dir="ltr"', false)
        ->assertSee(trans($titleKey, [], 'en'))
        ->assertSee(trans($labelKey, [], 'en'));
})->with('translated pages');
