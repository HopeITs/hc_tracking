<?php

declare(strict_types=1);

namespace App\Actions\RiskLibrary;

use App\Models\RiskLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Offer a deactivated library entry for new selection again.
 */
final class ReactivateRiskLibraryEntry
{
    /**
     * @throws ValidationException when the entry is already active (someone else got there first)
     */
    public function handle(int $entryId): RiskLibrary
    {
        return DB::transaction(function () use ($entryId): RiskLibrary {
            $entry = RiskLibrary::query()->lockForUpdate()->findOrFail($entryId);

            Gate::authorize('update', $entry);

            if ($entry->is_active) {
                throw ValidationException::withMessages(['entry' => __('risk_library.stale')]);
            }

            $entry->forceFill(['is_active' => true])->save();

            return $entry;
        });
    }
}
