<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\RecordsActivity;
use App\Enums\ReportTemplateStatus;
use App\Notifications\TracksActivity;
use Carbon\CarbonImmutable;
use Database\Factories\ReportTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property int $project_id
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
    ];

    /**
     * The project this template belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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
     * The project's name.
     */
    public function activityLabel(): string
    {
        return (string) Project::query()->whereKey($this->activityAttribute('project_id'))->value('name');
    }

    public function activityProgramId(): ?int
    {
        return Project::programIdFor($this->activityAttribute('project_id'));
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
     * @return array{project_id: int}
     */
    protected function activityProperties(string $event): array
    {
        return ['project_id' => (int) $this->activityAttribute('project_id')];
    }

    protected function activityEventForChange(string $field, mixed $old, mixed $new): ?string
    {
        if ($field !== 'status') {
            return null;
        }

        return ReportTemplateStatus::tryFrom((string) $new) === ReportTemplateStatus::Confirmed ? 'confirmed' : null;
    }
}
