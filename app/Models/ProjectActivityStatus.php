<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectActivityStatusFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The last status MEAL confirmed for an activity at a location row
 * (contracts §13, D-51; Y-15, D-93): one per (`proposal_location_id`,
 * `result_id`), on the row of the activity's own proposal at the report's
 * place. Written only by ValidateMonthlyReport; restored when a reopen is
 * granted. `project_id` is the row's project, still written until Y-18. The
 * model and table keep their names until Y-18.
 *
 * @property int $id
 * @property int|null $project_id
 * @property int $proposal_location_id
 * @property int $result_id
 * @property ActivityStatus $status
 * @property CarbonImmutable|null $started_on
 * @property CarbonImmutable|null $completed_on
 * @property int $monthly_report_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'project_id',
    'proposal_location_id',
    'result_id',
    'status',
    'started_on',
    'completed_on',
    'monthly_report_id',
])]
class ProjectActivityStatus extends Model
{
    /** @use HasFactory<ProjectActivityStatusFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The location row this status belongs to.
     *
     * @return BelongsTo<ProposalLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ProposalLocation::class, 'proposal_location_id');
    }

    /**
     * @return BelongsTo<Result, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Result::class, 'result_id');
    }

    /**
     * The monthly report whose validation last confirmed this status.
     *
     * @return BelongsTo<MonthlyReport, $this>
     */
    public function monthlyReport(): BelongsTo
    {
        return $this->belongsTo(MonthlyReport::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'proposal_location_id' => 'integer',
            'result_id' => 'integer',
            'status' => ActivityStatus::class,
            'started_on' => 'date',
            'completed_on' => 'date',
            'monthly_report_id' => 'integer',
        ];
    }
}
