<?php

declare(strict_types=1);

namespace App\Actions\Sectors;

use App\Models\Sector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Delete a sector no project uses. A used sector is deactivated instead;
 * `project_sector.sector_id` restricts on delete as the backstop, and that
 * refusal reads as the same "in use" message.
 */
final class DeleteSector
{
    /**
     * MySQL's "cannot delete or update a parent row" error.
     */
    private const int ForeignKeyRestricted = 1451;

    /**
     * @throws ValidationException when any project uses the sector
     */
    public function handle(int $sectorId): void
    {
        DB::transaction(function () use ($sectorId): void {
            $sector = Sector::query()->lockForUpdate()->findOrFail($sectorId);

            Gate::authorize('delete', $sector);

            if ($sector->projects()->exists()) {
                throw ValidationException::withMessages(['sector' => __('sectors.in_use')]);
            }

            try {
                /** @throws QueryException */
                $sector->delete();
            } catch (QueryException $exception) {
                if (($exception->errorInfo[1] ?? null) !== self::ForeignKeyRestricted) {
                    throw $exception;
                }

                throw ValidationException::withMessages(['sector' => __('sectors.in_use')]);
            }
        });
    }
}
