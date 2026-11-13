<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StrategicPlanStatus;
use Carbon\CarbonImmutable;
use Database\Factories\StrategicPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A program's strategic plan inside one strategy (MEAL manager request,
 * 2026-11-13): what must be achieved in each year of the strategy, measured
 * by chosen program indicators. Written by the MEAL manager, approved by the
 * program's manager (StrategicPlanPolicy). Workflow columns move only through
 * the actions in App\Actions\StrategicPlans.
 *
 * @property int $id
 * @property int $strategy_id
 * @property int $program_id
 * @property StrategicPlanStatus $status
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $submitted_by
 * @property CarbonImmutable|null $approved_at
 * @property int|null $approved_by
 * @property CarbonImmutable|null $sent_back_at
 * @property int|null $sent_back_by
 * @property string|null $sent_back_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['strategy_id', 'program_id'])]
class StrategicPlan extends Model
{
    /** @use HasFactory<StrategicPlanFactory> */
    use HasFactory;

    /**
     * Mirror the column default for strict mode.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * @return BelongsTo<Strategy, $this>
     */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * The plan's years, earliest first.
     *
     * @return HasMany<StrategicPlanYear, $this>
     */
    public function years(): HasMany
    {
        return $this->hasMany(StrategicPlanYear::class)->orderBy('year');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_back_by');
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'strategy_id' => 'integer',
            'program_id' => 'integer',
            'status' => StrategicPlanStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'sent_back_at' => 'datetime',
        ];
    }
}
