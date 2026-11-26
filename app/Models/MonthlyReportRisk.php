<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MonthlyReportRiskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A monthly report's answer for one logframe risk: whether it happened.
 *
 * @property int $id
 * @property int $monthly_report_id
 * @property int $risk_id
 * @property bool $happened
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['monthly_report_id', 'risk_id', 'happened'])]
class MonthlyReportRisk extends Model
{
    /** @use HasFactory<MonthlyReportRiskFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<MonthlyReport, $this>
     */
    public function monthlyReport(): BelongsTo
    {
        return $this->belongsTo(MonthlyReport::class);
    }

    /**
     * @return BelongsTo<Risk, $this>
     */
    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'happened' => 'boolean',
        ];
    }
}
