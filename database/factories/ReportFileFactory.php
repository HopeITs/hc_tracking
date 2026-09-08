<?php

namespace Database\Factories;

use App\Enums\FileType;
use App\Models\Report;
use App\Models\ReportFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportFile>
 */
class ReportFileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `date` is left to the column default so an upload is dated today.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(FileType::cases());

        return [
            'report_id' => Report::factory(),
            'path' => 'report-files/'.fake()->uuid().'.'.$type->value,
            'type' => $type,
        ];
    }
}
