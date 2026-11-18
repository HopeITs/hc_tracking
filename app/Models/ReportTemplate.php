<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\RecordsActivity;
use App\Enums\ReportTemplateStatus;
use App\Notifications\TracksActivity;
use App\Support\Reporting\ReportPlace;
use Carbon\CarbonImmutable;
use Database\Factories\ReportTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A project's monthly report template (contracts.md §12.4, T33): which
 * library indicators the center manager reports each month, plus the
 * project's narrative questions. One per project, pre-filled as `pending` on
 * donor approval; MEAL's confirmation activates an approved project
 * (ConfirmReportTemplate → ActivateProject).
 *
 * Nothing is mass assigned: the pre-filler creates the row with `project_id`
 * set directly, and `status` / `confirmed_*` move only through
 * ConfirmReportTemplate.
 *
 * @property int $id
 * @property int|null $project_id Only for templates that existed before Y-02.
 * @property int $city_id
 * @property int $program_id
 * @property int|null $center_id
 * @property bool $city_wide
 * @property ReportTemplateStatus $status
 * @property CarbonImmutable|null $confirmed_at
 * @property int|null $confirmed_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ReportTemplate extends Model implements TracksActivity
{
    /** @use HasFactory<ReportTemplateFactory> */
    use HasFactory, RecordsActivity;

    /**
     * Mirror the column default so a template that has not been round-tripped
     * through the database still reports it under strict mode.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'city_wide' => false,
    ];

    /**
     * The proposals this template reports for (Y-02, D-76).
     *
     * @return BelongsToMany<Proposal, $this>
     */
    public function proposals(): BelongsToMany
    {
        return $this->belongsToMany(Proposal::class, 'proposal_report_template')
            ->withPivot(['joined_at', 'joined_by']);
    }

    /**
     * The program of the template's place.
     *
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * The place the template reports for (D-75).
     */
    public function place(): ReportPlace
    {
        return new ReportPlace($this->city_id, $this->program_id, $this->center_id, $this->city_wide);
    }

    /**
     * Templates at the place. An unset place matches none (D-82).
     *
     * @param  Builder<ReportTemplate>  $query
     */
    public function scopeAtPlace(Builder $query, ReportPlace $place): void
    {
        if ($place->isUnset()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('city_id', $place->cityId)
            ->where('program_id', $place->programId)
            ->where('city_wide', $place->cityWide)
            ->where('center_id', $place->centerId);
    }

    /**
     * The project of a template that existed before Y-02; null for newer
     * ones. Read only through PlaceBridge::legacyProjectIdFor().
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The monthly reports filed against this template, one per month (D-76).
     *
     * @return HasMany<MonthlyReport, $this>
     */
    public function monthlyReports(): HasMany
    {
        return $this->hasMany(MonthlyReport::class, 'report_template_id');
    }

    /**
     * The indicator lines, one per target.
     *
     * @return HasMany<ReportTemplateLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ReportTemplateLine::class);
    }

    /**
     * The project's own copy of the narrative questions.
     *
     * @return HasMany<ReportTemplateQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(ReportTemplateQuestion::class);
    }

    /**
     * The tracking user who confirmed the template.
     *
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isConfirmed(): bool
    {
        return $this->status === ReportTemplateStatus::Confirmed;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'city_id' => 'integer',
            'program_id' => 'integer',
            'center_id' => 'integer',
            'city_wide' => 'boolean',
            'status' => ReportTemplateStatus::class,
            'confirmed_at' => 'datetime',
            'confirmed_by' => 'integer',
        ];
    }

    public function activityModule(): string
    {
        return 'report-templates';
    }

    /**
     * The project's name for a template that has one, else the program's.
     */
    public function activityLabel(): string
    {
        $projectId = $this->activityAttribute('project_id');

        if ($projectId !== null) {
            return (string) Project::query()->whereKey($projectId)->value('name');
        }

        return (string) Program::query()->whereKey($this->activityAttribute('program_id'))->first()?->name;
    }

    public function activityProgramId(): ?int
    {
        $programId = $this->activityAttribute('program_id');

        return $programId !== null ? (int) $programId : Project::programIdFor($this->activityAttribute('project_id'));
    }

    /**
     * Only the move to `confirmed` is a movement (report_template.confirmed);
     * lines added later are recorded by ConfirmReportTemplate as
     * report_template.lines_added through recordActivity().
     *
     * @return array<string, string>
     */
    public function activityEvents(): array
    {
        return ['status' => 'confirmed'];
    }

    /**
     * The project, so MEAL oversight reaches the project's responsible officer.
     *
     * @return array{project_id?: int}
     */
    protected function activityProperties(string $event): array
    {
        $projectId = $this->activityAttribute('project_id');

        return $projectId === null ? [] : ['project_id' => (int) $projectId];
    }

    protected function activityEventForChange(string $field, mixed $old, mixed $new): ?string
    {
        if ($field !== 'status') {
            return null;
        }

        return ReportTemplateStatus::tryFrom((string) $new) === ReportTemplateStatus::Confirmed ? 'confirmed' : null;
    }
}
