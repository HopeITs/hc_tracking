<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RemembersHrLookup;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * A department from the HR database (hchr_departments).
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $description_en
 * @property string|null $description_ar
 * @property string|null $email
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class HrDepartment extends HrModel
{
    use RemembersHrLookup;

    protected $table = 'departments';

    /**
     * The name of one HR department, empty when HR no longer has it.
     *
     * Read from an in-memory lookup (see RemembersHrLookup), so labelling many
     * employees by department costs one HR query.
     */
    public static function nameOf(int $id): string
    {
        return self::rememberedHrLookupEntry($id, fn (): array => static::query()
            ->toBase()
            ->pluck('name', 'id')
            ->map(fn (?string $name): string => trim((string) $name))
            ->all()) ?? '';
    }

    /**
     * The ids of every employee currently holding a position in this
     * department, via their active contract.
     *
     * A single query joining contracts to positions rather than a
     * whereHas() through HrEmployee's relations, matching how
     * HrEmployee::idsMatchingName() resolves ids before the caller ever
     * builds a query against the default (non-hr) connection.
     *
     * @return list<int>
     */
    public static function employeeIdsInDepartment(int $departmentId): array
    {
        return array_values(HrContract::query()
            ->join('positions', 'positions.id', '=', 'contracts.position_id')
            ->where('contracts.is_active', true)
            ->where('positions.department_id', $departmentId)
            ->distinct()
            ->pluck('contracts.employee_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * Departments ordered for a picker, id and name only.
     *
     * @return Collection<int, static>
     */
    public static function options(): Collection
    {
        return static::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();
    }
}
