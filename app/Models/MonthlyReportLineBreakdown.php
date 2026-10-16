<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Gender;
use Carbon\CarbonImmutable;
use Database\Factories\MonthlyReportLineBreakdownFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The male or female part of a line or share, with persons with disability
 * of that sex (contracts §13, D-50). `monthly_report_line_share_id` NULL is
 * a line-level row (`shared` mode).
 *
 * @property int $id
 * @property int $monthly_report_line_id
 * @property int|null $monthly_report_line_share_id
 * @property Gender $gender
 * @property string|null $value
 * @property string|null $numerator
 * @property string|null $denominator
 * @property string|null $disabled_value
 * @property string|null $disabled_numerator
 * @property string|null $disabled_denominator
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'monthly_report_line_id',
    'monthly_report_line_share_id',
    'gender',
    'value',
    'numerator',
    'denominator',
    'disabled_value',
    'disabled_numerator',
    'disabled_denominator',
])]
class MonthlyReportLineBreakdown extends Model
{
    /** @use HasFactory<MonthlyReportLineBreakdownFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<MonthlyReportLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(MonthlyReportLine::class, 'monthly_report_line_id');
    }

    /**
     * @return BelongsTo<MonthlyReportLineShare, $this>
     */
    public function share(): BelongsTo
    {
        return $this->belongsTo(MonthlyReportLineShare::class, 'monthly_report_line_share_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'monthly_report_line_id' => 'integer',
            'monthly_report_line_share_id' => 'integer',
            'gender' => Gender::class,
            'value' => 'decimal:2',
            'numerator' => 'decimal:2',
            'denominator' => 'decimal:2',
            'disabled_value' => 'decimal:2',
            'disabled_numerator' => 'decimal:2',
            'disabled_denominator' => 'decimal:2',
        ];
    }
}
