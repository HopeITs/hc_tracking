<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\RecordsActivity;
use App\Enums\DonorReportType;
use App\Enums\ReportAudience;
use App\Enums\ReportingPeriod;
use App\Notifications\TracksActivity;
use Carbon\CarbonImmutable;
use Database\Factories\DonorReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A report owed to a donor under one funding agreement.
 *
 * A composed report (T19) also carries its period, a frozen snapshot of the
 * validated monthly figures and a version; regenerating it adds a new row.
 * Hand-entered reports leave those columns empty.
 *
 * @property int $id
 * @property int $donor_proposal_id
 * @property string $title
 * @property DonorReportType $type
 * @property ReportAudience $audience
 * @property ReportingPeriod|null $period_type
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property CarbonImmutable $due_on
 * @property CarbonImmutable|null $submitted_on
 * @property array<string, mixed>|null $snapshot
 * @property int $version
 * @property int|null $responsible_employee_id
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['donor_proposal_id', 'title', 'type', 'audience', 'period_type', 'period_start', 'period_end', 'due_on', 'submitted_on', 'snapshot', 'version', 'responsible_employee_id', 'notes'])]
class DonorReport extends Model implements TracksActivity
{
    /** @use HasFactory<DonorReportFactory> */
    use HasFactory, RecordsActivity;

    /**
     * Mirrors the column defaults, so a new report reads as a narrative,
     * donor-facing first version before it is refreshed.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'narrative',
        'audience' => 'donor',
        'version' => 1,
    ];

    /**
     * The funding agreement this report is owed under.
     *
     * @return BelongsTo<DonorProposal, $this>
     */
    public function donorProposal(): BelongsTo
    {
        return $this->belongsTo(DonorProposal::class);
    }

    /**
     * The delivered report files (collection `report_submission`).
     *
     * @return MorphMany<Media, $this>
     */
    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable');
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_on !== null;
    }

    /**
     * Built from validated monthly figures, rather than entered by hand.
     */
    public function isComposed(): bool
    {
        return $this->period_type !== null;
    }

    /**
     * Whether this user is the person responsible for delivering the report.
     * Both employee ids must be known.
     */
    public function isResponsible(User $user): bool
    {
        return $this->responsible_employee_id !== null
            && $user->employee_id !== null
            && (int) $user->employee_id === $this->responsible_employee_id;
    }

    /**
     * Still unsent after its due date. A report is due on its date, not late.
     */
    public function isOverdue(): bool
    {
        return ! $this->isSubmitted() && $this->due_on->lt(CarbonImmutable::today());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'type' => DonorReportType::class,
            'audience' => ReportAudience::class,
            'period_type' => ReportingPeriod::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'due_on' => 'date',
            'submitted_on' => 'date',
            'snapshot' => 'array',
            'version' => 'integer',
            'responsible_employee_id' => 'integer',
        ];
    }

    public function activityModule(): string
    {
        return 'donor-reports';
    }

    public function activityLabel(): string
    {
        return (string) $this->activityAttribute('title');
    }

    public function activityProgramId(): ?int
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    public function activityEvents(): array
    {
        return ['submitted_on' => 'submitted', 'due_on' => 'updated', 'title' => 'updated'];
    }

    /**
     * @return list<string>
     */
    public function activityEmployeeFields(): array
    {
        return ['responsible_employee_id'];
    }

    /**
     * Only gaining a submission date is "submitted".
     */
    protected function activityEventForChange(string $field, mixed $old, mixed $new): ?string
    {
        if ($field === 'submitted_on') {
            return $new !== null ? 'submitted' : 'updated';
        }

        return $this->activityEvents()[$field] ?? null;
    }

    /**
     * A composed report is "saved" (a new version each time); a hand-entered
     * one keeps the generic created event.
     */
    protected function activityCreatedEvent(): string
    {
        return $this->activityAttribute('period_type') !== null ? 'donor_report.saved' : 'donor_report.created';
    }

    /**
     * @return array<string, mixed>
     */
    protected function activityProperties(string $event): array
    {
        return $event === 'donor_report.saved' ? ['version' => (int) $this->activityAttribute('version')] : [];
    }

    /**
     * The snapshot can hold amounts and is large: it never reaches the log.
     *
     * @return list<string>
     */
    protected function activityExcludedFields(): array
    {
        return ['snapshot'];
    }
}
