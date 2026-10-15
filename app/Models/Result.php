<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasTree;
use App\Concerns\RecordsActivity;
use App\Enums\ActivityKind;
use App\Enums\Period;
use App\Enums\ResultType;
use App\Notifications\TracksActivity;
use App\Observers\ResultObserver;
use Carbon\CarbonImmutable;
use Database\Factories\ResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A node in a logframe: an impact, outcome, output, activity or cross-cutting
 * statement.
 *
 * A result with no `proposal_id` belongs to its program's framework; one with
 * a `proposal_id` belongs to that proposal's own framework (contracts §11.2).
 * A child always carries its parent's `proposal_id` and `program_id`.
 *
 * @property int $id
 * @property string $statement
 * @property int|null $parent_id
 * @property int|null $program_id
 * @property int|null $proposal_id
 * @property int|null $contributes_to_result_id
 * @property int|null $copied_from_id
 * @property ResultType $type
 * @property ActivityKind|null $activity_kind
 * @property Period $checkout_period
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['statement', 'parent_id', 'program_id', 'proposal_id', 'contributes_to_result_id', 'copied_from_id', 'type', 'activity_kind', 'checkout_period'])]
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
     * The proposal whose framework this result belongs to; null for the
     * program framework.
     *
     * @return BelongsTo<Proposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /**
     * The program-framework result a proposal root contributes to.
     *
     * @return BelongsTo<Result, $this>
     */
    public function contributesTo(): BelongsTo
    {
        return $this->belongsTo(Result::class, 'contributes_to_result_id');
    }

    /**
     * The proposal roots contributing to this program-framework result.
     *
     * @return HasMany<Result, $this>
     */
    public function contributions(): HasMany
    {
        return $this->hasMany(Result::class, 'contributes_to_result_id');
    }

    /**
     * The result this one was copied from (renewal and strategy copies).
     *
     * @return BelongsTo<Result, $this>
     */
    public function copiedFrom(): BelongsTo
    {
        return $this->belongsTo(Result::class, 'copied_from_id');
    }

    /**
     * The milestones that deliver this activity.
     *
     * @return HasMany<Milestone, $this>
     */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class, 'result_id');
    }

    /**
     * The months this activity is scheduled in: the proposal calendar and
     * every center override (see ActivitySchedule).
     *
     * @return HasMany<ActivitySchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(ActivitySchedule::class);
    }

    /**
     * Results of a program's own framework, outside every proposal.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function programFramework(Builder $query): void
    {
        $query->whereNull('proposal_id');
    }

    /**
     * Results of a proposal's framework; every proposal when none is given.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function proposalFramework(Builder $query, ?int $proposalId = null): void
    {
        if ($proposalId === null) {
            $query->whereNotNull('proposal_id');

            return;
        }

        $query->where('proposal_id', $proposalId);
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
            'activity_kind' => ActivityKind::class,
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
        return ['statement' => 'updated', 'type' => 'updated', 'parent_id' => 'updated', 'program_id' => 'updated', 'contributes_to_result_id' => 'updated', 'activity_kind' => 'updated'];
    }
}
