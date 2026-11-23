<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasSlug;
use App\Concerns\RecordsActivity;
use App\Enums\MonthlyReportStatus;
use App\Notifications\TracksActivity;
use App\Support\MealCoverage;
use App\Support\MonthlyReportNames;
use Carbon\CarbonImmutable;
use Database\Factories\MonthlyReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One template's report for one month (contracts §13, D-76). Its lines come
 * from the confirmed report template; its figures reach `reports` as
 * mirrors, one per line share, so PlanIndicator::achievedValue() stays the
 * one achievement definition. Status moves only through the T17A actions.
 *
 * @property int $id
 * @property string $slug
 * @property int $report_template_id
 * @property int|null $project_id Only for reports that existed before Y-03, and the legacy mirror.
 * @property CarbonImmutable $period
 * @property MonthlyReportStatus $status
 * @property int|null $prepared_by
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $submitted_by
 * @property CarbonImmutable|null $validated_at
 * @property int|null $validated_by
 * @property CarbonImmutable|null $returned_at
 * @property int|null $returned_by
 * @property string|null $return_reason
 * @property CarbonImmutable|null $revised_due_on the deadline set when the report was returned after its own one passed
 * @property CarbonImmutable|null $seen_by_meal_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'report_template_id',
    'project_id',
    'period',
    'status',
    'prepared_by',
    'submitted_at',
    'submitted_by',
    'validated_at',
    'validated_by',
    'returned_at',
    'returned_by',
    'return_reason',
    'revised_due_on',
    'seen_by_meal_at',
])]
class MonthlyReport extends Model implements TracksActivity
{
    /** @use HasFactory<MonthlyReportFactory> */
    use HasFactory, HasSlug, RecordsActivity;

    /**
     * Mirror the column default so a report that has not been round-tripped
     * through the database still reports its status under strict mode.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * "report-{template id}-2026-10" (Y-03 slice 3: no project in it). Slugs
     * are set on create only, so the "project-slug-2026-10" of a report made
     * before Y-03 never changes and its URL keeps working.
     */
    protected function slugSource(): string
    {
        return 'report-'.$this->report_template_id.'-'.$this->period->format('Y-m');
    }

    /**
     * @return BelongsTo<ReportTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class, 'report_template_id');
    }

    /**
     * @deprecated Y-03 (D-76): a report belongs to its template; kept for the readers not moved yet.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<MonthlyReportLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(MonthlyReportLine::class);
    }

    /**
     * @return HasMany<MonthlyReportActivityEntry, $this>
     */
    public function activityEntries(): HasMany
    {
        return $this->hasMany(MonthlyReportActivityEntry::class);
    }

    /**
     * @return HasMany<MonthlyReportReopenRequest, $this>
     */
    public function reopenRequests(): HasMany
    {
        return $this->hasMany(MonthlyReportReopenRequest::class);
    }

    /**
     * @return HasMany<MonthlyReportAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(MonthlyReportAnswer::class);
    }

    /**
     * Every evidence file of the report, its lines and its activity entries.
     *
     * @return HasMany<MonthlyReportFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(MonthlyReportFile::class);
    }

    /**
     * The `reports` rows mirroring this report's shares.
     *
     * @return HasMany<Report, $this>
     */
    public function mirrors(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
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
    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /**
     * Reports this user may validate based on the template place. Excludes
     * reports they prepared or submitted. The MEAL Manager sees every place,
     * and the MEAL role can also validate places without a responsible officer.
     * The queue applies its submitted status filter separately.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function validatableBy(Builder $query, User $user): void
    {
        if (! $user->can('reports.approve') || $user->hasRole('General Manager')) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->where(fn (Builder $own) => $own->whereNull('prepared_by')->orWhere('prepared_by', '!=', $user->id))
            ->where(fn (Builder $own) => $own->whereNull('submitted_by')->orWhere('submitted_by', '!=', $user->id));

        if ($user->employee_id !== null && $user->employee_id === MealCoverage::mealManagerEmployeeId()) {
            return;
        }

        $templates = MealCoverage::scopeCoveredBy(
            ReportTemplate::query(),
            (int) $user->employee_id,
            includeUnassigned: $user->hasRole('MEAL'),
        );

        $query->whereIn('report_template_id', $templates->select('id'));
    }

    /**
     * Whether the manager may still change the report.
     */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'report_template_id' => 'integer',
            'project_id' => 'integer',
            'period' => 'date',
            'status' => MonthlyReportStatus::class,
            'prepared_by' => 'integer',
            'submitted_at' => 'datetime',
            'submitted_by' => 'integer',
            'validated_at' => 'datetime',
            'validated_by' => 'integer',
            'returned_at' => 'datetime',
            'returned_by' => 'integer',
            'revised_due_on' => 'date',
            'seen_by_meal_at' => 'datetime',
        ];
    }

    public function activityModule(): string
    {
        return 'monthly-reports';
    }

    /**
     * The template's program, read by id so nothing is lazy-loaded; a report
     * without a template falls back to its project.
     */
    public function activityProgramId(): ?int
    {
        $templateId = $this->activityAttribute('report_template_id');

        if ($templateId !== null) {
            $programId = ReportTemplate::query()->whereKey($templateId)->value('program_id');

            return $programId === null ? null : (int) $programId;
        }

        return Project::programIdFor($this->activityAttribute('project_id'));
    }

    /**
     * "Proposals · Place · 2026-10", in English like every stored label
     * (MonthlyReportNames::label()): the proposals tell two reports of one
     * place and month apart. Read by template id, so nothing is lazy-loaded.
     */
    public function activityLabel(): string
    {
        $period = CarbonImmutable::parse($this->activityAttribute('period'));
        $template = ReportTemplate::query()->whereKey($this->activityAttribute('report_template_id'))->first();

        return $template === null ? $period->format('Y-m') : app(MonthlyReportNames::class)->label($template, $period);
    }

    /**
     * @return array<string, string>
     */
    public function activityEvents(): array
    {
        return [
            'status' => 'status_changed',
            'seen_by_meal_at' => 'seen_by_meal',
        ];
    }

    /**
     * Draft → submitted is the submission, submitted → validated the
     * validation, submitted → draft a return. A granted reopen (validated →
     * draft) is announced by the reopen decision, not here.
     */
    protected function activityEventForChange(string $field, mixed $old, mixed $new): ?string
    {
        $from = $old instanceof MonthlyReportStatus ? $old->value : $old;
        $to = $new instanceof MonthlyReportStatus ? $new->value : $new;

        return match (true) {
            $field === 'status' && $from === 'draft' && $to === 'submitted' => 'submitted',
            $field === 'status' && $from === 'submitted' && $to === 'validated' => 'validated',
            $field === 'status' && $from === 'submitted' && $to === 'draft' => 'returned',
            $field === 'seen_by_meal_at' => $old === null && $new !== null ? 'seen_by_meal' : null,
            default => null,
        };
    }
}
