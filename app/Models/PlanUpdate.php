<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanUpdateStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PlanUpdateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The monthly update of an annual plan for one centre (project) and month.
 *
 * @property int $id
 * @property int $activity_plan_id
 * @property int $project_id
 * @property CarbonImmutable $month
 * @property PlanUpdateStatus $status
 * @property string|null $activities_implemented
 * @property string|null $challenges
 * @property string|null $next_month
 * @property string|null $accountability
 * @property int|null $males
 * @property int|null $females
 * @property int|null $with_disability
 * @property CarbonImmutable|null $sent_at
 * @property int|null $sent_by
 * @property bool $resent
 * @property CarbonImmutable|null $officer_approved_at
 * @property int|null $officer_approved_by
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $seen_at
 * @property int|null $seen_by
 * @property string|null $program_manager_note
 * @property CarbonImmutable|null $returned_at
 * @property int|null $returned_by
 * @property string|null $returned_to
 * @property string|null $return_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'activity_plan_id', 'project_id', 'month', 'status',
    'activities_implemented', 'challenges', 'next_month', 'accountability',
    'males', 'females', 'with_disability',
    'sent_at', 'sent_by', 'resent', 'officer_approved_at', 'officer_approved_by',
    'reviewed_at', 'reviewed_by', 'seen_at', 'seen_by', 'program_manager_note',
    'returned_at', 'returned_by', 'returned_to', 'return_reason',
])]
class PlanUpdate extends Model
{
    /** @use HasFactory<PlanUpdateFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'resent' => false,
    ];

    /**
     * @return BelongsTo<ActivityPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ActivityPlan::class, 'activity_plan_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<PlanUpdateWeek, $this>
     */
    public function weeks(): HasMany
    {
        return $this->hasMany(PlanUpdateWeek::class);
    }

    /**
     * @return HasMany<PlanUpdateNumber, $this>
     */
    public function numbers(): HasMany
    {
        return $this->hasMany(PlanUpdateNumber::class);
    }

    /**
     * @return HasMany<PlanUpdateFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(PlanUpdateFile::class)->orderBy('id');
    }

    /**
     * @return HasMany<PlanUpdateQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(PlanUpdateQuestion::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function officer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'officer_approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function seer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seen_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'activity_plan_id' => 'integer',
            'project_id' => 'integer',
            'month' => 'date',
            'status' => PlanUpdateStatus::class,
            'males' => 'integer',
            'females' => 'integer',
            'with_disability' => 'integer',
            'sent_at' => 'datetime',
            'sent_by' => 'integer',
            'resent' => 'boolean',
            'officer_approved_at' => 'datetime',
            'officer_approved_by' => 'integer',
            'reviewed_at' => 'datetime',
            'reviewed_by' => 'integer',
            'seen_at' => 'datetime',
            'seen_by' => 'integer',
            'returned_at' => 'datetime',
            'returned_by' => 'integer',
        ];
    }
}
