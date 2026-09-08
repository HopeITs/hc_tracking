<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasTree;
use App\Observers\ResultObserver;
use Carbon\CarbonImmutable;
use Database\Factories\ResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node in the logframe: an impact, outcome or output statement.
 *
 * @property int $id
 * @property string $statement
 * @property int|null $parent_id
 * @property int|null $program_id
 * @property string $type
 * @property string $checkout_period
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['statement', 'parent_id', 'program_id', 'type', 'checkout_period'])]
#[ObservedBy(ResultObserver::class)]
class Result extends Model
{
    /** @use HasFactory<ResultFactory> */
    use HasFactory;

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
}
