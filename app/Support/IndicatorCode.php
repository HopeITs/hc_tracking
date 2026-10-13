<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\HrProgram;
use App\Models\LibraryIndicator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Generates Indicator Library codes: `{program code}.{level_ref}.{serial}`,
 * e.g. `002.OP1.2.3`, or `HC.OP1.1` for an institutional entry (D-10).
 *
 * The program code is read from HR. The serial is the next number within
 * (program, level): the existing rows are read with lockForUpdate() on the
 * (program_id, level_ref, serial) index, so a second save at the same program
 * and level waits for the first to commit. Deactivated entries keep their
 * serial, so it is never handed out again.
 */
final class IndicatorCode
{
    /**
     * The prefix of an entry that belongs to no program.
     */
    public const string Institutional = 'HC';

    /**
     * The next code and serial for an entry at this program and level.
     *
     * @return array{code: string, serial: int}
     *
     * @throws LogicException outside a transaction (the lock would be released at once)
     * @throws ValidationException when HR has given the program's code to another program too
     */
    public static function next(?int $programId, string $levelRef): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('IndicatorCode::next() must run inside the save transaction.');
        }

        $prefix = $programId === null ? self::Institutional : self::programCode($programId);

        $latest = LibraryIndicator::query()
            ->when(
                $programId === null,
                fn ($query) => $query->whereNull('program_id'),
                fn ($query) => $query->where('program_id', $programId),
            )
            ->where('level_ref', $levelRef)
            ->orderByDesc('serial')
            ->lockForUpdate()
            ->value('serial');

        $serial = (int) $latest + 1;

        return ['code' => "{$prefix}.{$levelRef}.{$serial}", 'serial' => $serial];
    }

    /**
     * The HR code of a program, refused when HR has given it to another
     * program as well (D-10a): codes must be unique across the organisation.
     *
     * @throws ValidationException
     */
    private static function programCode(int $programId): string
    {
        $code = trim((string) HrProgram::query()->whereKey($programId)->value('code'));

        if ($code === '') {
            throw ValidationException::withMessages(['program_id' => __('indicator_library.program_required')]);
        }

        if (HrProgram::query()->where('code', $code)->count() > 1) {
            throw ValidationException::withMessages(['program_id' => __('indicator_library.program_code_duplicated')]);
        }

        return $code;
    }
}
