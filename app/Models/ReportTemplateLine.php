<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CollectionFrequency;
use App\Enums\IndicatorCollector;
use App\Enums\LineSchedule;
use App\Enums\ReportTemplateLineStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ReportTemplateLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One target a project reports in its monthly reports (contracts.md §12.4):
 * the schedule, who enters it, the fixed denominator and the evidence it
 * requires. Only library-linked targets get a line.
 *
 * `status` and `confirmed_*` are never mass assigned: they move only through
 * ConfirmReportTemplate and RetireReportTemplateLine. A confirmed line is
 * never deleted; retiring it sets `effective_to` to the current month.
 *
 * @property int $id
 * @property int $report_template_id
 * @property int $plan_indicator_id
 * @property ReportTemplateLineStatus $status
 * @property LineSchedule $schedule
 * @property list<int>|null $schedule_months
 * @property int|null $once_after_months
 * @property IndicatorCollector $entered_by
 * @property int|null $fixed_denominator
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property CarbonImmutable|null $confirmed_at
 * @property int|null $confirmed_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['plan_indicator_id', 'schedule', 'schedule_months', 'once_after_months', 'entered_by', 'fixed_denominator', 'effective_from', 'effective_to'])]
class ReportTemplateLine extends Model
{
    /** @use HasFactory<ReportTemplateLineFactory> */
    use HasFactory;

    /**
     * Mirror the column defaults so a line that has not been round-tripped
     * through the database still reports them under strict mode.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'schedule' => 'library_default',
    ];

    /**
     * The template this line belongs to.
     *
     * @return BelongsTo<ReportTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class, 'report_template_id');
    }

    /**
     * The target reported: a proposal target or an annual-plan org-wide
     * target, carrying the indicator, project and source.
     *
     * @return BelongsTo<PlanIndicator, $this>
     */
    public function planIndicator(): BelongsTo
    {
        return $this->belongsTo(PlanIndicator::class);
    }

    /**
     * The evidence types this line requires.
     *
     * @return BelongsToMany<EvidenceType, $this>
     */
    public function evidenceTypes(): BelongsToMany
    {
        return $this->belongsToMany(EvidenceType::class, 'report_template_line_evidence_types')->withTimestamps();
    }

    /**
     * Whether this line is due in the given month (contracts.md §12.4).
     *
     * Only a `confirmed` line inside `effective_from` ≤ month ≤ `effective_to`
     * (by month) is ever due. With i the whole months from `effective_from`
     * (0-based), `library_default` follows the library frequency: monthly and
     * per-event every month; quarterly, semi-annual and annual when
     * (i + 1) is a multiple of 3, 6 or 12; follow-up when i equals
     * `follow_up_months`; per-season, baseline-endline and one-time never.
     * `monthly` is every month, `selected_months` the listed calendar months
     * and `once_after_months` the month where i equals N.
     *
     * Pure: it reads `planIndicator.indicator.libraryIndicator`, which the
     * caller eager-loads, and never queries.
     */
    public function isDueIn(CarbonImmutable $month): bool
    {
        if ($this->status !== ReportTemplateLineStatus::Confirmed) {
            return false;
        }

        $month = $month->startOfMonth();
        $from = $this->effective_from->startOfMonth();

        if ($month->lessThan($from) || ($this->effective_to !== null && $month->greaterThan($this->effective_to->startOfMonth()))) {
            return false;
        }

        $index = ($month->year - $from->year) * 12 + ($month->month - $from->month);

        return match ($this->schedule) {
            LineSchedule::Monthly => true,
            LineSchedule::SelectedMonths => in_array($month->month, array_map(intval(...), $this->schedule_months ?? []), true),
            LineSchedule::OnceAfterMonths => $this->once_after_months !== null && $index === $this->once_after_months,
            LineSchedule::LibraryDefault => $this->isDueByLibraryFrequency($index),
        };
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'report_template_id' => 'integer',
            'plan_indicator_id' => 'integer',
            'status' => ReportTemplateLineStatus::class,
            'schedule' => LineSchedule::class,
            'schedule_months' => 'array',
            'once_after_months' => 'integer',
            'entered_by' => IndicatorCollector::class,
            'fixed_denominator' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'confirmed_at' => 'datetime',
            'confirmed_by' => 'integer',
        ];
    }

    /**
     * The library frequency's rule for month index i; a target with no
     * library link is never due.
     */
    private function isDueByLibraryFrequency(int $index): bool
    {
        $library = $this->planIndicator?->indicator?->libraryIndicator;

        if ($library === null) {
            return false;
        }

        return match ($library->frequency) {
            CollectionFrequency::Monthly, CollectionFrequency::PerEvent => true,
            CollectionFrequency::Quarterly => ($index + 1) % 3 === 0,
            CollectionFrequency::SemiAnnual => ($index + 1) % 6 === 0,
            CollectionFrequency::Annual => ($index + 1) % 12 === 0,
            CollectionFrequency::FollowUp => $library->follow_up_months !== null && $index === $library->follow_up_months,
            CollectionFrequency::PerSeason, CollectionFrequency::BaselineEndline, CollectionFrequency::OneTime => false,
        };
    }
}
