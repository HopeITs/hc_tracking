<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
class Result extends Model
{
    /** @use HasFactory<ResultFactory> */
    use HasFactory;

    /**
     * The result this one contributes to.
     *
     * @return BelongsTo<Result, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Result::class, 'parent_id');
    }

    /**
     * The results that contribute to this one.
     *
     * @return HasMany<Result, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Result::class, 'parent_id');
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
