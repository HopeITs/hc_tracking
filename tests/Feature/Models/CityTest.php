<?php

use App\Models\City;
use App\Models\Project;

test('a city has the projects delivered in it', function () {
    $city = City::factory()->create();
    Project::factory(2)->create(['city_id' => $city->id]);
    Project::factory()->create();

    expect($city->projects)->toHaveCount(2)
        ->and($city->projects->first())->toBeInstanceOf(Project::class);
});
