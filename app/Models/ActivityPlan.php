<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasSlug;
use App\Enums\ActivityPlanStatus;
use App\Support\PlanCalendar;
use Carbon\CarbonImmutable;
use Database\Factories\ActivityPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The annual activity plan of one program in one proposal (2026-11-11): the
 * logframe activities the program manager commits to, week by week, and the
 * follow-up tasks MEAL attaches when approving it.
 *
 * @property int $id
 * @property string $slug
 * @property int $proposal_id
 * @property int $program_id
 * @property ActivityPlanStatus $status
 * @property int $step
 * @property int|null $created_by
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
#[Fillable([
    'proposal_id',
    'program_id',
    'status',
    'step',
    'created_by',
    'submitted_at',
    'submitted_by',
    'approved_at',
    'approved_by',
    'sent_back_at',
    'sent_back_by',
    'sent_back_reason',
])]
class ActivityPlan extends Model
{
    /** @use HasFactory<ActivityPlanFactory> */
    use HasFactory, HasSlug;

    /**
     * Mirror the column defaults for strict mode.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'step' => 0,
    ];

    /**
     * "proposal-slug-program-slug"; read by id so creating a plan never
     * lazy-loads its relations.
     */
    protected function slugSource(): string
    {
        $proposal = (string) Proposal::query()->whereKey($this->proposal_id)->value('slug');

        return $proposal.'-'.HrProgram::slugOf($this->program_id);
    }

    /**
     * @return BelongsTo<Proposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * The planned activities, in logframe order.
     *
     * @return HasMany<ActivityPlanItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ActivityPlanItem::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * @return HasMany<ActivityPlanFollowUp, $this>
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(ActivityPlanFollowUp::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
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
    public function sentBackBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_back_by');
    }

    /**
     * The Monday weeks the plan runs on, from its proposal's period.
     */
    public function calendar(): PlanCalendar
    {
        $proposal = $this->relationLoaded('proposal') ? $this->proposal : $this->proposal()->firstOrFail();

        return PlanCalendar::forProposal($proposal);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'proposal_id' => 'integer',
            'program_id' => 'integer',
            'status' => ActivityPlanStatus::class,
            'step' => 'integer',
            'created_by' => 'integer',
            'submitted_at' => 'datetime',
            'submitted_by' => 'integer',
            'approved_at' => 'datetime',
            'approved_by' => 'integer',
            'sent_back_at' => 'datetime',
            'sent_back_by' => 'integer',
        ];
    }
}
