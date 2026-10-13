<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\JoinClause;

/**
 * A position from the HR database (hchr_positions).
 *
 * Only the columns tracking needs are documented; the rest (technical
 * manager, qualifications, staffing counts) exist in the table but are not
 * read here.
 *
 * @property int $id
 * @property int $department_id
 * @property int|null $program_id
 * @property int|null $center_id
 * @property int|null $city_id
 * @property int|null $job_level_id
 * @property int|null $direct_manager_id
 * @property string $title_en
 * @property string $title_ar
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class HrPosition extends HrModel
{
    protected $table = 'positions';

    /**
     * The holder of each program's "Program Manager" position, null when vacant.
     *
     * Only active positions count, and only an active contract makes someone
     * the holder, so a program whose seat is empty maps to null while a program
     * with no such position is absent. The lowest employee id wins when a seat
     * has several holders.
     *
     * @return array<int, int|null> employee id by program id
     */
    public static function programManagerEmployeeIds(): array
    {
        return static::query()
            ->leftJoin('contracts', fn (JoinClause $join) => $join
                ->on('contracts.position_id', '=', 'positions.id')
                ->where('contracts.is_active', true))
            ->where('positions.is_active', true)
            ->whereNotNull('positions.program_id')
            ->where('positions.title_en', 'like', '%Program Manager%')
            ->orderBy('contracts.employee_id', 'desc')
            ->get(['positions.program_id', 'contracts.employee_id'])
            ->pluck('employee_id', 'program_id')
            ->map(fn (mixed $id): ?int => $id === null ? null : (int) $id)
            ->all();
    }

    /**
     * The department this position belongs to.
     *
     * @return BelongsTo<HrDepartment, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(HrDepartment::class, 'department_id');
    }

    /**
     * The program this position sits under, when it has one.
     *
     * @return BelongsTo<HrProgram, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(HrProgram::class, 'program_id');
    }

    /**
     * The center this position works from, when it has one.
     *
     * @return BelongsTo<HrCenter, $this>
     */
    public function center(): BelongsTo
    {
        return $this->belongsTo(HrCenter::class, 'center_id');
    }

    /**
     * The job level of this position.
     *
     * @return BelongsTo<HrJobLevel, $this>
     */
    public function jobLevel(): BelongsTo
    {
        return $this->belongsTo(HrJobLevel::class, 'job_level_id');
    }

    /**
     * The city of this position.
     *
     * @return BelongsTo<HrCity, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(HrCity::class, 'city_id');
    }

    /**
     * The position this one reports to (positions.direct_manager_id); its
     * holder is the HR direct manager (see EmployeeProfile::managerOf()).
     *
     * @return BelongsTo<self, $this>
     */
    public function directManager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'direct_manager_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
