<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\RecordsActivity;
use App\Notifications\TracksActivity;
use Carbon\CarbonImmutable;
use Database\Factories\StrategicPillarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A strategic pillar of a strategy (contracts §11.2, D-35). Each program sits
 * under at most one pillar, always one of its own strategy's. Written only
 * through SaveStrategicPillar / DeleteStrategicPillar; programs are placed by
 * AssignProgramToPillar.
 *
 * @property int $id
 * @property int $strategy_id
 * @property string $code
 * @property string $name_ar
 * @property string|null $name_en
 * @property string|null $goal_ar
 * @property string|null $goal_en
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['strategy_id', 'code', 'name_ar', 'name_en', 'goal_ar', 'goal_en', 'sort_order'])]
class StrategicPillar extends Model implements TracksActivity
{
    /** @use HasFactory<StrategicPillarFactory> */
    use HasFactory, RecordsActivity;

    /**
     * Mirror the column default so a pillar that has not been round-tripped
     * through the database still reports it under strict mode.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'strategy_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The strategy this pillar belongs to.
     *
     * @return BelongsTo<Strategy, $this>
     */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }

    /**
     * The programs under this pillar.
     *
     * @return HasMany<Program, $this>
     */
    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }

    /**
     * The name in the current locale: Arabic in Arabic, otherwise the English
     * name, falling back to the Arabic one while it is blank.
     */
    public function localizedName(): string
    {
        if (app()->getLocale() === 'ar' || blank($this->name_en)) {
            return $this->name_ar;
        }

        return (string) $this->name_en;
    }

    /**
     * The goal in the current locale, with the same fallback as the name.
     */
    public function localizedGoal(): ?string
    {
        if (app()->getLocale() === 'ar' || blank($this->goal_en)) {
            return $this->goal_ar ?? $this->goal_en;
        }

        return $this->goal_en;
    }

    public function activityModule(): string
    {
        return 'strategies';
    }

    public function activityLabel(): string
    {
        return (string) $this->activityAttribute('name_ar');
    }

    public function activityProgramId(): ?int
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    public function activityEvents(): array
    {
        return [
            'code' => 'updated', 'name_ar' => 'updated', 'name_en' => 'updated',
            'goal_ar' => 'updated', 'goal_en' => 'updated', 'sort_order' => 'updated',
        ];
    }
}
