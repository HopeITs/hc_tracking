<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\RecordsParentActivity;
use Carbon\CarbonImmutable;
use Database\Factories\IndicatorProgressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dated reading against an indicator.
 *
 * Distinct from a Report: a report is a narrative document for a period and
 * measures a plan_indicators target; this is a single number with the evidence
 * it came from.
 *
 * @property int $id
 * @property int $indicator_id
 * @property CarbonImmutable $date
 * @property string $value
 * @property string|null $notes
 * @property string|null $source_of_verification
 * @property int $recorded_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['indicator_id', 'date', 'value', 'notes', 'source_of_verification', 'recorded_by'])]
class IndicatorProgress extends Model
{
    /** @use HasFactory<IndicatorProgressFactory> */
    use HasFactory, RecordsParentActivity;

    /**
     * Eloquent would pluralize this to `indicator_progresses`.
     *
     * @var string
     */
    protected $table = 'indicator_progress';

    /**
     * The indicator this reading measures.
     *
     * @return BelongsTo<Indicator, $this>
     */
    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    /**
     * The staff member who recorded this reading.
     *
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'value' => 'decimal:2',
        ];
    }

    /**
     * Readings are logged on their indicator.
     */
    public function activityParent(): ?Indicator
    {
        return $this->indicator()->first();
    }

    protected function parentActivityEvent(string $hook): ?string
    {
        return $hook === 'created' ? 'indicator.progress_recorded' : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function parentActivityProperties(string $hook): array
    {
        return ['value' => rtrim(rtrim((string) $this->value, '0'), '.')];
    }
}
