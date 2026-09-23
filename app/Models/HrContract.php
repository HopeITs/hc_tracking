<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A contract from the HR database (hchr_contracts) — the link between an
 * employee and the position they currently (or previously) held.
 *
 * Only the columns tracking needs are documented; salary, work hours and
 * termination details exist in the table but are not read here.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $position_id
 * @property CarbonImmutable|null $start_date
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class HrContract extends HrModel
{
    protected $table = 'contracts';

    /**
     * The employee this contract belongs to.
     *
     * @return BelongsTo<HrEmployee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    /**
     * The position this contract is for.
     *
     * @return BelongsTo<HrPosition, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'position_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
