<?php

declare(strict_types=1);

namespace App\Actions\EvidenceTypes;

use App\Models\EvidenceType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Take an evidence type off new selection. It stays wherever it is already
 * used, shown with an "Inactive" badge.
 */
final class DeactivateEvidenceType
{
    /**
     * @throws ValidationException when the type is already inactive (someone else got there first)
     */
    public function handle(int $evidenceTypeId): EvidenceType
    {
        return DB::transaction(function () use ($evidenceTypeId): EvidenceType {
            $evidenceType = EvidenceType::query()->lockForUpdate()->findOrFail($evidenceTypeId);

            Gate::authorize('update', $evidenceType);

            if (! $evidenceType->is_active) {
                throw ValidationException::withMessages(['evidence_type' => __('evidence_types.stale')]);
            }

            $evidenceType->forceFill(['is_active' => false])->save();

            return $evidenceType;
        });
    }
}
