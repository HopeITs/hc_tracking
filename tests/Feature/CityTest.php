<?php

use App\Models\Project;

test('a project resolves its city from the hr database', function () {
    $cityId = insertHrCity('Deir Az-zor', 'دير الزور');

    $project = Project::factory()->create(['city_id' => $cityId]);

    expect($project->city->name_en)->toBe('Deir Az-zor')
        ->and($project->city->name_ar)->toBe('دير الزور');
});

test('a project whose hr city no longer exists has no city rather than failing', function () {
    $project = Project::factory()->create(['city_id' => 999999]);

    expect($project->fresh()->city)->toBeNull();
});
