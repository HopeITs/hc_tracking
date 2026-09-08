<?php

use App\Models\Project;
use App\Models\Risk;

test('a risk belongs to a project', function () {
    $project = Project::factory()->create();
    $risk = Risk::factory()->create(['project_id' => $project->id]);

    expect($risk->project)->toBeInstanceOf(Project::class)
        ->and($risk->project->id)->toBe($project->id);
});

test('a risk casts happened to a boolean', function () {
    expect(Risk::factory()->create()->fresh()->happened)->toBeFalse()
        ->and(Risk::factory()->happened()->create()->fresh()->happened)->toBeTrue();
});
