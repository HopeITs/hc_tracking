<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssumptionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AssumptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What must hold for one result of a proposal's logical framework to be
 * reached, and whether it still does.
 *
 * @property int $id
 * @property int $proposal_id
 * @property int $result_id
 * @property string $statement
 * @property AssumptionStatus $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['proposal_id', 'result_id', 'statement', 'status'])]
class Assumption extends Model
{
    /** @use HasFactory<AssumptionFactory> */
    use HasFactory;

    /**
     * Mirrors the column default for strict mode.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'holds',
    ];

    /**
     * @return BelongsTo<Proposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /**
     * @return BelongsTo<Result, $this>
     */
    public function result(): BelongsTo
    {
        return $this->belongsTo(Result::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AssumptionStatus::class,
        ];
    }
}
