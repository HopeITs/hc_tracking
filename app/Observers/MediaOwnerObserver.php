<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Media;
use App\Models\Milestone;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;

/**
 * Removes a media owner's files when the owner is deleted, and bridges the
 * database cascades that would otherwise hide an owner's disappearance.
 *
 * `media.mediable_id` carries no foreign key — a polymorphic column cannot —
 * so nothing at the database level removes these rows. This observer is the
 * only thing that does.
 */
class MediaOwnerObserver
{
    /**
     * Delete the owner's media, and any nested owner whose own media would
     * otherwise be orphaned by a database cascade.
     *
     * The extra hops look redundant next to the `cascadeOnDelete()` on
     * `milestones.project_id` and `milestones.parent_milestone_id`, but they
     * are load-bearing for exactly the reason `ProgramObserver` documents: a
     * database cascade fires NO Eloquent events, so without deleting the
     * nested rows through Eloquent first, they vanish, this observer never
     * runs for them, and their media rows are left pointing at nothing.
     */
    public function deleting(Model $model): void
    {
        if ($model instanceof Project) {
            // milestones.project_id cascades. Delete them through Eloquent so
            // each one's media is removed by this same observer.
            $model->milestones()->whereNull('parent_milestone_id')->get()->each->delete();
        }

        if ($model instanceof Milestone) {
            // parent_milestone_id cascades; recurse through Eloquent instead.
            $model->children()->get()->each->delete();
        }

        Media::query()
            ->where('mediable_type', $model->getMorphClass())
            ->where('mediable_id', $model->getKey())
            ->delete();
    }
}
