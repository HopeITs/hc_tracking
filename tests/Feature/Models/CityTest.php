<?php

use App\Models\HrCity;
use App\Models\Project;

test('projects delivered in an hr city can be found by its id', function () {
    $city = HrCity::find(insertHrCity());
    Project::factory(2)->create(['city_id' => $city->id]);
    Project::factory()->create(['city_id' => insertHrCity('Homs')]);

    $projects = Project::query()->where('city_id', $city->id)->with('city')->get();

    expect($projects)->toHaveCount(2)
        ->and($projects->first()->city->is($city))->toBeTrue();
});
