<?php

declare(strict_types=1);

namespace App\Actions\RiskLibrary;

use App\Models\RiskLibrary;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Delete a library entry no risk was picked from. A used entry is
 * deactivated instead; `risks.library_risk_id` restricts on delete as the
 * backstop, and that refusal reads as the same "in use" message.
 */
final class DeleteRiskLibraryEntry
{
    /**
     * MySQL's "cannot delete or update a parent row" error.
     */
    private const int ForeignKeyRestricted = 1451;

    /**
     * @throws ValidationException when any risk was picked from the entry
     */
    public function handle(int $entryId): void
    {
        DB::transaction(function () use ($entryId): void {
            $entry = RiskLibrary::query()->lockForUpdate()->findOrFail($entryId);

            Gate::authorize('delete', $entry);

            if ($entry->risks()->exists()) {
                throw ValidationException::withMessages(['entry' => __('risk_library.in_use')]);
            }

            try {
                /** @throws QueryException */
                $entry->delete();
            } catch (QueryException $exception) {
                if (($exception->errorInfo[1] ?? null) !== self::ForeignKeyRestricted) {
                    throw $exception;
                }

                throw ValidationException::withMessages(['entry' => __('risk_library.in_use')]);
            }
        });
    }
}
