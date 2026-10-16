<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityStatus;
use Carbon\CarbonImmutable;
use Database\Factories\MonthlyReportActivityEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What happened to one logframe activity in a monthly report's month
 * (contracts §13, D-51).
 *
 * @property int $id
 * @property int $monthly_report_id
 * @property int $result_id
 * @property ActivityStatus|null $status
 * @property string|null $reason
 * @property CarbonImmutable|null $started_on
 * @property CarbonImmutable|null $completed_on
 * @property CarbonImmutable|null $executed_on
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'monthly_report_id',
    'result_id',
    'status',
    'reason',
    'started_on',
    'completed_on',
    'executed_on',
    'note',
])]
class MonthlyReportActivityEntry extends Model
{
    /** @use HasFactory<MonthlyReportActivityEntryFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<MonthlyReport, $this>
     */
    public function monthlyReport(): BelongsTo
    {
        return $this->belongsTo(MonthlyReport::class);
    }

    /**
     * The logframe activity (a `results` row of type activity).
     *
     * @return BelongsTo<Result, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Result::class, 'result_id');
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
            'result_id' => 'integer',
            'status' => ActivityStatus::class,
            'started_on' => 'date',
            'completed_on' => 'date',
            'executed_on' => 'date',
        ];
    }
}
