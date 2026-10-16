<?php

declare(strict_types=1);

namespace App\Actions\EvidenceTypes;

use App\Models\EvidenceType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Delete an evidence type nothing uses. A used type is deactivated instead;
 * the consumers' foreign keys restrict on delete as the backstop, and that
 * refusal reads as the same "in use" message.
 */
final class DeleteEvidenceType
{
    /**
     * MySQL's "cannot delete or update a parent row" error.
     */
    private const int ForeignKeyRestricted = 1451;

    /**
     * @throws ValidationException when anything uses the type
     */
    public function handle(int $evidenceTypeId): void
    {
        DB::transaction(function () use ($evidenceTypeId): void {
            $evidenceType = EvidenceType::query()->lockForUpdate()->findOrFail($evidenceTypeId);

            Gate::authorize('delete', $evidenceType);

            if ($evidenceType->isInUse()) {
                throw ValidationException::withMessages(['evidence_type' => __('evidence_types.in_use')]);
            }

            try {
                /** @throws QueryException */
                $evidenceType->delete();
            } catch (QueryException $exception) {
                if (($exception->errorInfo[1] ?? null) !== self::ForeignKeyRestricted) {
                    throw $exception;
                }

                throw ValidationException::withMessages(['evidence_type' => __('evidence_types.in_use')]);
            }
        });
    }
}
