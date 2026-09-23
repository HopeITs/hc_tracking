<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A position from the HR database (hchr_positions).
 *
 * Only the columns tracking needs are documented; the rest (job level,
 * managers, qualifications, staffing counts) exist in the table but are not
 * read here.
 *
 * @property int $id
 * @property int $department_id
 * @property int|null $program_id
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
