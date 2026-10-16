<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasSlug;
use App\Concerns\RecordsActivity;
use App\Notifications\TracksActivity;
use Carbon\CarbonImmutable;
use Database\Factories\StrategyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $start_year
 * @property int $end_year
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'start_year', 'end_year'])]
class Strategy extends Model implements TracksActivity
{
    /** @use HasFactory<StrategyFactory> */
    use HasFactory, HasSlug, RecordsActivity;

    protected function slugSource(): string
    {
        return $this->name;
    }

    /**
     * The programs delivered under this strategy.
     *
     * @return HasMany<Program, $this>
     */
    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }

    /**
     * The strategic pillars of this strategy (D-35), in display order.
     *
     * @return HasMany<StrategicPillar, $this>
     */
    public function pillars(): HasMany
    {
        return $this->hasMany(StrategicPillar::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_year' => 'integer',
            'end_year' => 'integer',
        ];
    }

    public function activityModule(): string
    {
        return 'strategies';
    }

    public function activityLabel(): string
    {
        return (string) $this->activityAttribute('name');
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
        return ['name' => 'updated', 'start_year' => 'updated', 'end_year' => 'updated'];
    }
}
