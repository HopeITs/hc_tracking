<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasTree;
use App\Concerns\RecordsActivity;
use App\Enums\Period;
use App\Enums\ResultType;
use App\Notifications\TracksActivity;
use App\Observers\ResultObserver;
use Carbon\CarbonImmutable;
use Database\Factories\ResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A node in the logframe: an impact, outcome or output statement.
 *
 * @property int $id
 * @property string $statement
 * @property int|null $parent_id
 * @property int|null $program_id
 * @property ResultType $type
 * @property Period $checkout_period
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['statement', 'parent_id', 'program_id', 'type', 'checkout_period'])]
#[ObservedBy(ResultObserver::class)]
class Result extends Model implements TracksActivity
{
    /** @use HasFactory<ResultFactory> */
    use HasFactory, RecordsActivity;

    use HasTree;

    /**
     * The column pointing at the result this one contributes to.
     */
    public static function parentKeyName(): string
    {
        return 'parent_id';
    }

    /**
     * The program this result sits in, if it is not global.
     *
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * The indicators that measure this result.
     *
     * @return HasMany<Indicator, $this>
     */
    public function indicators(): HasMany
    {
        return $this->hasMany(Indicator::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'type' => ResultType::class,
            'checkout_period' => Period::class,
        ];
    }

    public function activityModule(): string
    {
        return 'results';
    }

    public function activityLabel(): string
    {
        return Str::limit((string) $this->activityAttribute('statement'), 80);
    }

    public function activityProgramId(): ?int
    {
        $programId = $this->activityAttribute('program_id');

        return $programId === null ? null : (int) $programId;
    }

    /**
     * @return array<string, string>
     */
    public function activityEvents(): array
    {
        return ['statement' => 'updated', 'type' => 'updated', 'parent_id' => 'updated', 'program_id' => 'updated'];
    }
}
