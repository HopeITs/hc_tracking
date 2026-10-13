<?php

declare(strict_types=1);

namespace App\Actions\IndicatorLibrary;

use App\Models\LibraryIndicator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Offer a deactivated Indicator Library entry for new selection again.
 */
class ReactivateLibraryIndicator
{
    /**
     * @throws ValidationException when the entry is already active (someone else got there first)
     */
    public function handle(int $libraryIndicatorId): LibraryIndicator
    {
        return DB::transaction(function () use ($libraryIndicatorId): LibraryIndicator {
            $entry = LibraryIndicator::query()->lockForUpdate()->findOrFail($libraryIndicatorId);

            Gate::authorize('update', $entry);

            if ($entry->is_active) {
                throw ValidationException::withMessages(['entry' => __('indicator_library.stale')]);
            }

            $entry->forceFill(['is_active' => true])->save();

            return $entry;
        });
    }
}
