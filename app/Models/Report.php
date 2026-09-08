<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An actual value reported against a planned target.
 *
 * @property int $id
 * @property int $plan_indicator_id
 * @property int $user_id
 * @property CarbonImmutable $planned_date
 * @property string $value
 * @property string|null $narrative_report_notes
 * @property CarbonImmutable|null $submit_date
 * @property bool $is_approved
 * @property CarbonImmutable|null $approved_date
 * @property bool $is_editable
 * @property CarbonImmutable|null $edited_date
 * @property bool $is_seen_by_meal
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'plan_indicator_id',
    'user_id',
    'planned_date',
    'value',
    'narrative_report_notes',
    'submit_date',
    'is_approved',
    'approved_date',
    'is_editable',
    'edited_date',
    'is_seen_by_meal',
])]
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    /**
     * The target this actual was reported against.
     *
     * @return BelongsTo<PlanIndicator, $this>
     */
    public function planIndicator(): BelongsTo
    {
        return $this->belongsTo(PlanIndicator::class);
    }

    /**
     * The user who submitted this report.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The means of verification uploaded with this report.
     *
     * @return HasMany<ReportFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(ReportFile::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'planned_date' => 'date',
            'value' => 'decimal:2',
            'submit_date' => 'date',
            'is_approved' => 'boolean',
            'approved_date' => 'date',
            'is_editable' => 'boolean',
            'edited_date' => 'date',
            'is_seen_by_meal' => 'boolean',
        ];
    }
}
