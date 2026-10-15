<?php

declare(strict_types=1);

namespace App\Actions\Sectors;

use App\Models\Sector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Offer a deactivated sector for new selection again.
 */
final class ReactivateSector
{
    /**
     * @throws ValidationException when the sector is already active (someone else got there first)
     */
    public function handle(int $sectorId): Sector
    {
        return DB::transaction(function () use ($sectorId): Sector {
            $sector = Sector::query()->lockForUpdate()->findOrFail($sectorId);

            Gate::authorize('update', $sector);

            if ($sector->is_active) {
                throw ValidationException::withMessages(['sector' => __('sectors.stale')]);
            }

            $sector->forceFill(['is_active' => true])->save();

            return $sector;
        });
    }
}
