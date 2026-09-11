<?php

namespace Database\Factories;

use App\Enums\FileType;
use App\Enums\MediaCollection;
use App\Models\Media;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `collection`, `disk` and `uploaded_at` are left to the column defaults so
     * the schema tests keep exercising them. The owner defaults to a Project
     * because that is the common case; use forMilestone() for the other.
     *
     * `mediable_type` is set to the morph map KEY, not a class name, exactly as
     * the column stores it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Indexed rather than fake()->randomElement(), which is typed mixed and
        // would leave the mime match below unable to prove it is exhaustive.
        $types = FileType::cases();
        $type = $types[array_rand($types)];
        $name = fake()->slug(3).'.'.$type->value;

        return [
            'mediable_type' => 'project',
            'mediable_id' => Project::factory(),
            'path' => 'media/'.fake()->uuid().'.'.$type->value,
            'original_name' => $name,
            'mime_type' => match ($type) {
                FileType::Pdf => 'application/pdf',
                FileType::Docx => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                FileType::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                FileType::Jpg => 'image/jpeg',
                FileType::Png => 'image/png',
            },
            'size' => fake()->numberBetween(20_000, 8_000_000),
            'type' => $type,
            'uploaded_by' => User::factory(),
        ];
    }

    /**
     * Attach this file to a milestone rather than a project.
     *
     * Takes the id directly so the caller controls which milestone, and so the
     * default Project factory above is not created and then discarded.
     */
    public function forMilestone(int $milestoneId): static
    {
        return $this->state([
            'mediable_type' => 'milestone',
            'mediable_id' => $milestoneId,
            'collection' => MediaCollection::Evidence,
        ]);
    }

    /**
     * Put this file in a named collection.
     */
    public function inCollection(MediaCollection $collection): static
    {
        return $this->state(['collection' => $collection]);
    }
}
