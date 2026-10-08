<?php

declare(strict_types=1);

namespace App\Actions\Milestones;

use App\Models\Milestone;

/**
 * Write a milestone's fields. Each form sends only the fields it shows, so a
 * field one form does not offer (the calendar has no description, the
 * directory no sequence) is never blanked by that form.
 */
class SaveMilestone
{
    /**
     * @param  array<string, mixed>  $fields  column => value; already validated and authorised by the page
     */
    public function handle(?Milestone $existing, array $fields): Milestone
    {
        $milestone = $existing ?? new Milestone;
        $milestone->fill($fields)->save();

        return $milestone;
    }
}
