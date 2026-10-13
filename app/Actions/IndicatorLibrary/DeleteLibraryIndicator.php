<?php

declare(strict_types=1);

namespace App\Actions\IndicatorLibrary;

use App\Models\LibraryIndicator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Delete an Indicator Library entry no indicator uses. An entry in use is
 * deactivated instead; `indicators.library_indicator_id` restricts on delete
 * as the backstop, and that refusal reads as the same "in use" message.
 */
class DeleteLibraryIndicator
{
    /**
     * MySQL's "cannot delete or update a parent row" error.
     */
    private const int ForeignKeyRestricted = 1451;

    /**
     * @throws ValidationException when any indicator uses the entry
     */
    public function handle(int $libraryIndicatorId): void
    {
        DB::transaction(function () use ($libraryIndicatorId): void {
            $entry = LibraryIndicator::query()->lockForUpdate()->findOrFail($libraryIndicatorId);

            Gate::authorize('delete', $entry);

            if ($entry->indicators()->exists()) {
                throw ValidationException::withMessages(['entry' => __('indicator_library.in_use')]);
            }

            try {
                /** @throws QueryException */
                $entry->delete();
            } catch (QueryException $exception) {
                if (($exception->errorInfo[1] ?? null) !== self::ForeignKeyRestricted) {
                    throw $exception;
                }

                throw ValidationException::withMessages(['entry' => __('indicator_library.in_use')]);
            }
        });
    }
}
