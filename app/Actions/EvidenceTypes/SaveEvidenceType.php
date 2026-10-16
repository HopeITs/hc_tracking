<?php

declare(strict_types=1);

namespace App\Actions\EvidenceTypes;

use App\Models\EvidenceType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Create or rename an evidence type (contracts.md §12.4). Only the two names
 * come from the form: a new type is active and goes last, and the flag and
 * the position move only through their own actions.
 */
final class SaveEvidenceType
{
    /**
     * @param  array{name_ar?: ?string, name_en?: ?string}  $input
     *
     * @throws ValidationException
     */
    public function handle(?int $evidenceTypeId, array $input): EvidenceType
    {
        if ($evidenceTypeId === null) {
            Gate::authorize('create', EvidenceType::class);
        } else {
            Gate::authorize('update', EvidenceType::query()->findOrFail($evidenceTypeId));
        }

        $validated = $this->validate($input);

        return DB::transaction(function () use ($evidenceTypeId, $validated): EvidenceType {
            $evidenceType = $evidenceTypeId === null
                ? new EvidenceType
                : EvidenceType::query()->lockForUpdate()->findOrFail($evidenceTypeId);

            $evidenceType->fill($validated);

            if (! $evidenceType->exists) {
                $evidenceType->forceFill(['sort_order' => (int) EvidenceType::query()->lockForUpdate()->max('sort_order') + 1]);
            }

            $evidenceType->save();

            return $evidenceType;
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
                'name_ar.required' => __('evidence_types.enter_arabic_name'),
                'name_ar.max' => __('evidence_types.arabic_name_too_long'),
                'name_en.max' => __('evidence_types.english_name_too_long'),
            ],
        )->validate();
    }
}
