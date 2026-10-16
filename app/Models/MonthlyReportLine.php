<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IndicatorCollector;
use Carbon\CarbonImmutable;
use Database\Factories\MonthlyReportLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One library indicator due in a monthly report (contracts §13, §13.1 item
 * 1). Its figures are computed by the server from its shares (`split`) or
 * its breakdown (`shared`). `entered_by = meal` lines are filled by the
 * validator (D-48a).
 *
 * @property int $id
 * @property int $monthly_report_id
 * @property int $library_indicator_id
 * @property IndicatorCollector $entered_by
 * @property string|null $value
 * @property string|null $numerator
 * @property string|null $denominator
 * @property int|null $fixed_denominator
 * @property bool $not_measured
 * @property string|null $note
 * @property int|null $filled_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'monthly_report_id',
    'library_indicator_id',
    'entered_by',
    'value',
    'numerator',
    'denominator',
    'fixed_denominator',
    'not_measured',
    'note',
    'filled_by',
])]
class MonthlyReportLine extends Model
{
    /** @use HasFactory<MonthlyReportLineFactory> */
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
     * @return BelongsTo<MonthlyReport, $this>
     */
    public function monthlyReport(): BelongsTo
    {
        return $this->belongsTo(MonthlyReport::class);
    }

    /**
     * @return BelongsTo<LibraryIndicator, $this>
     */
    public function libraryIndicator(): BelongsTo
    {
        return $this->belongsTo(LibraryIndicator::class);
    }

    /**
     * One share per covering target, in both attribution modes.
     *
     * @return HasMany<MonthlyReportLineShare, $this>
     */
    public function shares(): HasMany
    {
        return $this->hasMany(MonthlyReportLineShare::class);
    }

    /**
     * Every breakdown row of the line, line-level and per share.
     *
     * @return HasMany<MonthlyReportLineBreakdown, $this>
     */
    public function breakdowns(): HasMany
    {
        return $this->hasMany(MonthlyReportLineBreakdown::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function filler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filled_by');
    }

    /**
     * @return HasMany<MonthlyReportFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(MonthlyReportFile::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'monthly_report_id' => 'integer',
            'library_indicator_id' => 'integer',
            'entered_by' => IndicatorCollector::class,
            'value' => 'decimal:2',
            'numerator' => 'decimal:2',
            'denominator' => 'decimal:2',
            'fixed_denominator' => 'integer',
            'not_measured' => 'boolean',
            'filled_by' => 'integer',
        ];
    }
}
