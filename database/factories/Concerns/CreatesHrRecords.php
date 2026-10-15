<?php

namespace Database\Factories\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Creates the HR rows a tracking factory points at.
 *
 * HR data is read-only to this application, so there are no HR model factories.
 * These inserts only succeed against the test suite's in-memory HR database: the
 * real `hr` connection opens every session READ ONLY and rejects them. Seeders
 * therefore always pass real HR ids explicitly instead of relying on defaults.
 */
trait CreatesHrRecords
{
    /**
     * Insert an HR city and return its id.
     */
    protected function createHrCity(): int
    {
        return DB::connection('hr')->table('cities')->insertGetId([
            'name_en' => fake()->city(),
            'name_ar' => fake()->city(),
            'slug' => fake()->unique()->slug(3),
            'code' => fake()->unique()->bothify('??######'),
        ]);
    }

    /**
     * Insert an HR department and return its id.
     */
    protected function createHrDepartment(): int
    {
        return DB::connection('hr')->table('departments')->insertGetId([
            'name' => fake()->unique()->words(2, true),
            'code' => fake()->unique()->bothify('??####'),
        ]);
    }

    /**
     * Insert an HR employee and return its id.
     */
    protected function createHrEmployee(): int
    {
        return DB::connection('hr')->table('employees')->insertGetId([
            'first_name_en' => fake()->firstName(),
            'last_name_en' => fake()->lastName(),
            'first_name_ar' => fake()->firstName(),
            'last_name_ar' => fake()->lastName(),
            'username' => fake()->unique()->userName(),
        ]);
    }
}
