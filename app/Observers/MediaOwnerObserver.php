<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Media;
use Illuminate\Database\Eloquent\Model;

/**
 * Removes a media owner's files when the owner is deleted.
 *
 * `media.mediable_id` carries no foreign key — a polymorphic column cannot —
 * so nothing at the database level removes these rows. This observer is the
 * only thing that does.
 */
class MediaOwnerObserver
{
    /**
     * Delete the owner's media.
     */
    public function deleting(Model $model): void
    {
        Media::query()
            ->where('mediable_type', $model->getMorphClass())
            ->where('mediable_id', $model->getKey())
            ->delete();
    }
}
