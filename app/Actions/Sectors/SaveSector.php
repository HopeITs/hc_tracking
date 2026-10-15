<?php

declare(strict_types=1);

namespace App\Actions\Sectors;

use App\Models\Sector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Create or rename a sector (contracts.md §12.5). Only the two names come
 * from the form: a new sector is active and goes last, and the flag and the
 * position move only through their own actions.
 */
final class SaveSector
{
    /**
     * @param  array{name_ar?: ?string, name_en?: ?string}  $input
     *
     * @throws ValidationException
     */
    public function handle(?int $sectorId, array $input): Sector
    {
        if ($sectorId === null) {
            Gate::authorize('create', Sector::class);
        } else {
            Gate::authorize('update', Sector::query()->findOrFail($sectorId));
        }

        $validated = $this->validate($input);

        return DB::transaction(function () use ($sectorId, $validated): Sector {
            $sector = $sectorId === null
                ? new Sector
                : Sector::query()->lockForUpdate()->findOrFail($sectorId);

            $sector->fill($validated);

            if (! $sector->exists) {
                $sector->forceFill(['sort_order' => (int) Sector::query()->lockForUpdate()->max('sort_order') + 1]);
            }

            $sector->save();

            return $sector;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name_ar: string, name_en: ?string}
     */
    private function validate(array $input): array
    {
        $trimmed = fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;

        /** @var array{name_ar: string, name_en: ?string} */
        return Validator::make(
            ['name_ar' => $trimmed($input['name_ar'] ?? null), 'name_en' => $trimmed($input['name_en'] ?? null)],
            [
                'name_ar' => ['required', 'string', 'max:255'],
                'name_en' => ['nullable', 'string', 'max:255'],
            ],
            [
                'name_ar.required' => __('sectors.enter_arabic_name'),
                'name_ar.max' => __('sectors.arabic_name_too_long'),
                'name_en.max' => __('sectors.english_name_too_long'),
            ],
        )->validate();
    }
}
