<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MonthlyReportLineShareFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A line's figures for one covering target (contracts §13, D-46). In
 * `shared` mode it carries the line's figures; in `split` the manager enters
 * it and the line is the sum. Mirrored into one `reports` row.
 * `plan_indicator_id` is never accepted from the request.
 *
 * @property int $id
 * @property int $monthly_report_line_id
 * @property int $plan_indicator_id
 * @property string|null $value
 * @property string|null $numerator
 * @property string|null $denominator
 * @property int|null $fixed_denominator
 * @property bool $not_measured
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'monthly_report_line_id',
    'plan_indicator_id',
    'value',
    'numerator',
    'denominator',
    'fixed_denominator',
    'not_measured',
])]
class MonthlyReportLineShare extends Model
{
    /** @use HasFactory<MonthlyReportLineShareFactory> */
    use HasFactory;

    /**
     * Mirror the column default for strict mode.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'not_measured' => false,
    ];

    /**
     * @return BelongsTo<MonthlyReportLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(MonthlyReportLine::class, 'monthly_report_line_id');
    }

    /**
     * @return BelongsTo<PlanIndicator, $this>
     */
    public function planIndicator(): BelongsTo
    {
        return $this->belongsTo(PlanIndicator::class);
    }

    /**
     * @return HasMany<MonthlyReportLineBreakdown, $this>
     */
    public function breakdowns(): HasMany
    {
        return $this->hasMany(MonthlyReportLineBreakdown::class);
    }

    /**
     * The `reports` row mirroring this share.
     *
     * @return HasOne<Report, $this>
     */
    public function mirror(): HasOne
    {
        return $this->hasOne(Report::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monthly_report_line_id' => 'integer',
            'plan_indicator_id' => 'integer',
            'value' => 'decimal:2',
            'numerator' => 'decimal:2',
            'denominator' => 'decimal:2',
            'fixed_denominator' => 'integer',
            'not_measured' => 'boolean',
        ];
    }
}
