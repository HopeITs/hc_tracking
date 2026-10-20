<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MonthlyReports;

use App\Actions\MonthlyReports\SaveMonthlyReportAnswers;
use App\Enums\ActivityKind;
use App\Enums\ActivityStatus;
use App\Enums\DonorAttribution;
use App\Enums\IndicatorCollector;
use App\Enums\IndicatorMeasure;
use App\Enums\MonthlyReportEntryKind;
use App\Enums\MonthlyReportStatus;
use App\Enums\ReopenRequestStatus;
use App\Models\EvidenceType;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportAnswer;
use App\Models\MonthlyReportFile;
use App\Models\MonthlyReportLine;
use App\Models\MonthlyReportLineShare;
use App\Models\MonthlyReportReopenRequest;
use App\Models\Proposal;
use App\Models\ReportTemplateLine;
use App\Models\ReportTemplateQuestion;
use App\Support\MonthlyReportActivities;
use App\Support\MonthlyReportLineBuilder;
use App\Support\MonthlyReportPeriods;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The read side of monthly-reports/show (T17B): the report with every line,
 * share, breakdown and reopen request in a fixed number of queries, the
 * grid rows shaped in PHP, the abilities the page renders by, and the one
 * helper that forgets all of it after a write.
 *
 * Figures and totals are the ones the server stored (SaveMonthlyReportLines
 * computes them); the page never computes achievement or percentages.
 *
 * @property-read MonthlyReport $report  #[Computed] report()
 * @property-read MonthlyReportPeriods $periods  #[Computed] periods()
 * @property-read DonorAttribution $attribution  #[Computed] attribution()
 * @property-read array{update: bool, fillMealLines: bool, submit: bool, validate: bool, return: bool, requestReopen: bool, markSeen: bool, delete: bool} $abilities  #[Computed] abilities()
 * @property-read MonthlyReportReopenRequest|null $decidableRequest  #[Computed] decidableRequest()
 * @property-read string|null $submitBlocker  #[Computed] submitBlocker()
 * @property-read list<array<string, mixed>> $rows  #[Computed] rows()
 * @property-read list<array{id: int, code: string, name: string}> $incompleteLines  #[Computed] incompleteLines()
 * @property-read int $pendingMealLines  #[Computed] pendingMealLines()
 * @property-read array<int, list<string>> $missingEvidence  #[Computed] missingEvidence()
 * @property-read Collection<int, EvidenceType> $evidenceTypes  #[Computed] evidenceTypes()
 * @property-read list<array<string, mixed>> $activities  #[Computed] activities()
 * @property-read Collection<int, ReportTemplateQuestion> $questions  #[Computed] questions()
 * @property-read array{done: int, total: int, percent: int, needed: list<string>}|null $progress  #[Computed] progress()
 */
trait ReadsMonthlyReport
{
    #[Locked]
    public int $monthlyReportId;

    /**
     * The status the page rendered, sent back as every action's
     * `expectedStatus`; refreshed only from the server.
     */
    #[Locked]
    public string $renderedStatus = '';

    #[Computed]
    public function report(): MonthlyReport
    {
        $report = MonthlyReport::query()
            ->with([
                'project:id,slug,name,user_id,program_id,center_id,city_id,working_days,start_date,end_date',
                'project.program:id,donor_attribution',
                'lines' => fn (Relation $query) => $query->orderBy('id'),
                'lines.libraryIndicator',
                'lines.shares' => fn (Relation $query) => $query->orderBy('id'),
                'lines.shares.planIndicator:id,indicator_id,target,period,source_type,source_id,project_id',
                'lines.shares.planIndicator.source',
                'lines.breakdowns',
                'files' => fn (Relation $query) => $query->orderBy('id'),
                'files.evidenceType:id,name_ar,name_en',
                'answers',
                'activityEntries',
                'reopenRequests' => fn (Relation $query) => $query->latest('id'),
                'reopenRequests.requester:id,hr_user_id',
                'reopenRequests.decider:id,hr_user_id',
                'preparer:id,hr_user_id',
                'submitter:id,hr_user_id',
                'validator:id,hr_user_id',
                'returner:id,hr_user_id',
            ])
            ->findOrFail($this->monthlyReportId);

        $proposals = $report->lines
            ->flatMap(fn (MonthlyReportLine $line) => $line->shares)
            ->map(fn (MonthlyReportLineShare $share) => $share->planIndicator->source)
            ->filter(fn (mixed $source): bool => $source instanceof Proposal)
            ->unique('id');

        (new Collection($proposals->all()))->load('donor:id,name');

        return $report;
    }

    #[Computed]
    public function periods(): MonthlyReportPeriods
    {
        return MonthlyReportPeriods::for($this->report->project);
    }

    #[Computed]
    public function attribution(): DonorAttribution
    {
        return MonthlyReportLineBuilder::attributionOf($this->report->project);
    }

    /**
     * What the viewer may do on this report, by MonthlyReportPolicy.
     *
     * @return array{update: bool, fillMealLines: bool, submit: bool, validate: bool, return: bool, requestReopen: bool, markSeen: bool, delete: bool}
     */
    #[Computed]
    public function abilities(): array
    {
        $user = auth()->user();
        $report = $this->report;

        return [
            'update' => $user->can('update', $report),
            'fillMealLines' => $user->can('fillMealLines', $report),
            'submit' => $user->can('submit', $report),
            'validate' => $user->can('validate', $report),
            'return' => $user->can('return', $report),
            'requestReopen' => $user->can('requestReopen', $report),
            'markSeen' => $user->can('markSeen', $report),
            'delete' => $user->can('delete', $report),
        ];
    }

    /**
     * The pending reopen request the viewer may decide, if any.
     */
    #[Computed]
    public function decidableRequest(): ?MonthlyReportReopenRequest
    {
        $pending = $this->report->reopenRequests->firstWhere('status', ReopenRequestStatus::Pending);

        return $pending !== null && auth()->user()->can('decideReopen', [$this->report, $pending]) ? $pending : null;
    }

    /**
     * Why Submit is not available yet, or null.
     */
    #[Computed]
    public function submitBlocker(): ?string
    {
        return $this->report->status === MonthlyReportStatus::Draft ? $this->periods->canSubmit($this->report) : null;
    }

    /**
     * The grid rows, sorted by indicator code, everything the grid shows.
     *
     * @return list<array{
     *     id: int,
     *     code: string,
     *     name: string,
     *     secondary_name: ?string,
     *     frequency_note: ?string,
     *     kind: MonthlyReportEntryKind,
     *     unit: string,
     *     meal: bool,
     *     editable: bool,
     *     disaggregated: bool,
     *     split: bool,
     *     numerator_label: string,
     *     denominator_label: string,
     *     fixed_denominator: ?int,
     *     target: string,
     *     computed: string,
     *     note: ?string,
     *     filled: bool,
     *     shares: list<array{id: int, donor: ?string, proposal: ?Proposal, target: string, fixed_denominator: ?int, computed: string, filled: bool}>,
     *     files: list<array{id: int, name: string, type: string, url: string}>,
     *     missing_evidence: list<string>,
     * }>
     */
    #[Computed]
    public function rows(): array
    {
        $report = $this->report;
        $split = $this->attribution === DonorAttribution::Split;
        $abilities = $this->abilities;
        $rows = [];

        foreach ($report->lines as $line) {
            $library = $line->libraryIndicator;
            $kind = MonthlyReportEntryKind::for($library);
            $meal = $line->entered_by === IndicatorCollector::Meal;
            $unit = $library->measure === IndicatorMeasure::Percentage ? '%' : '';
            $arabic = app()->getLocale() === 'ar';
            $other = $arabic ? $library->name_en : $library->name_ar;
            $targets = array_values($line->shares->map(fn (MonthlyReportLineShare $share): float => (float) $share->planIndicator->target)->all());
            $period = $line->shares->first()?->planIndicator->period;

            $shares = [];

            foreach ($line->shares as $share) {
                $source = $share->planIndicator->source;
                $proposal = $source instanceof Proposal ? $source : null;

                $shares[] = [
                    'id' => $share->id,
                    'donor' => $proposal?->donor?->name,
                    'proposal' => $proposal,
                    'target' => self::figure((float) $share->planIndicator->target).$unit,
                    'fixed_denominator' => $share->fixed_denominator,
                    'computed' => self::computed($share->value, $share->not_measured, $unit),
                    'filled' => $kind->isFilled($share->value, $share->numerator, $share->not_measured),
                ];
            }

            $rows[] = [
                'id' => $line->id,
                'code' => $library->code,
                'name' => $library->localizedName(),
                'secondary_name' => filled($other) && $other !== $library->localizedName() ? $other : null,
                'frequency_note' => $library->frequency_note,
                'kind' => $kind,
                'unit' => $unit,
                'meal' => $meal,
                'editable' => $meal
                    ? $report->status === MonthlyReportStatus::Submitted && $abilities['fillMealLines']
                    : $report->status === MonthlyReportStatus::Draft && $abilities['update'],
                'disaggregated' => $library->is_disaggregated,
                'split' => $split && count($shares) > 0,
                'numerator_label' => filled($library->numerator_label) ? $library->numerator_label : ($kind === MonthlyReportEntryKind::FixedRatio ? __('monthly_reports.count') : __('monthly_reports.numerator')),
                'denominator_label' => filled($library->denominator_label) ? $library->denominator_label : __('monthly_reports.denominator'),
                'fixed_denominator' => $line->fixed_denominator,
                'target' => $targets === [] ? '—' : self::figure($this->attribution->combine($targets)).$unit.($period === null ? '' : ' · '.$period->label()),
                'computed' => self::computed($line->value, $line->not_measured, $unit),
                'note' => $line->note,
                'filled' => $split && $shares !== []
                    ? collect($shares)->every(fn (array $share): bool => $share['filled'])
                    : $kind->isFilled($line->value, $line->numerator, $line->not_measured),
                'shares' => $shares,
                'files' => $this->fileRows($line->id, null),
                'missing_evidence' => $meal ? [] : ($this->missingEvidence[$line->id] ?? []),
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a['code'], $a['id']] <=> [$b['code'], $b['id']]);

        return $rows;
    }

    /**
     * Manager lines still empty: what Submit would refuse.
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    #[Computed]
    public function incompleteLines(): array
    {
        return array_values(array_map(
            fn (array $row): array => ['id' => $row['id'], 'code' => $row['code'], 'name' => $row['name']],
            array_filter($this->rows, fn (array $row): bool => ! $row['meal'] && ! $row['filled']),
        ));
    }

    /**
     * MEAL lines not filled yet: what Validate waits for (D-48a).
     */
    #[Computed]
    public function pendingMealLines(): int
    {
        return count(array_filter($this->rows, fn (array $row): bool => $row['meal'] && ! $row['filled']));
    }

    /**
     * The evidence types each manager line still needs (D-42): the union of
     * the types its shares' template lines require, less the types already
     * attached to the line. The same rule SubmitMonthlyReport enforces.
     * Two queries, whatever the number of lines.
     *
     * @return array<int, list<string>> type names keyed by line id
     */
    #[Computed]
    public function missingEvidence(): array
    {
        $report = $this->report;
        $managerLines = $report->lines->filter(fn (MonthlyReportLine $line): bool => $line->entered_by !== IndicatorCollector::Meal);
        $targetIds = $managerLines->flatMap(fn (MonthlyReportLine $line) => $line->shares->pluck('plan_indicator_id'))->unique()->values();

        if ($targetIds->isEmpty()) {
            return [];
        }

        $requiredByTarget = ReportTemplateLine::query()
            ->whereHas('template', fn (Builder $query) => $query->where('project_id', $report->project_id))
            ->whereIn('plan_indicator_id', $targetIds)
            ->with('evidenceTypes:id,name_ar,name_en')
            ->get(['id', 'report_template_id', 'plan_indicator_id'])
            ->mapWithKeys(fn (ReportTemplateLine $templateLine): array => [$templateLine->plan_indicator_id => $templateLine->evidenceTypes->all()]);

        $missing = [];

        foreach ($managerLines as $line) {
            $attached = $report->files->where('monthly_report_line_id', $line->id)->pluck('evidence_type_id')->all();
            $names = array_values($line->shares
                ->flatMap(fn (MonthlyReportLineShare $share): array => $requiredByTarget->get($share->plan_indicator_id, []))
                ->unique('id')
                ->reject(fn (EvidenceType $type): bool => in_array($type->id, $attached, true))
                ->map(fn (EvidenceType $type): string => $type->localizedName())
                ->all());

            if ($names !== []) {
                $missing[$line->id] = $names;
            }
        }

        return $missing;
    }

    /**
     * The active evidence types, for the upload dialog.
     *
     * @return Collection<int, EvidenceType>
     */
    #[Computed]
    public function evidenceTypes(): Collection
    {
        return EvidenceType::query()->active()->ordered()->get(['id', 'name_ar', 'name_en']);
    }

    /**
     * The activities this month (contracts §13, D-49b, D-51), shaped for the
     * page: the statuses the activity's kind allows, its planned weeks, its
     * carry-over badge, its saved entry and that entry's files.
     *
     * @return list<array{id: int, statement: string, statuses: list<ActivityStatus>, entry_id: ?int, status: ?ActivityStatus, reason: ?string, started_on: ?string, completed_on: ?string, executed_on: ?string, note: ?string, planned: ?string, carried: ?string, late: bool, files: list<array{id: int, name: string, type: string, url: string}>}>
     */
    #[Computed]
    public function activities(): array
    {
        $entries = $this->report->activityEntries->keyBy('result_id');
        $rows = [];

        foreach (MonthlyReportActivities::for($this->report) as $row) {
            $activity = $row['activity'];
            $entry = $entries->get($activity->id);
            $carried = $row['carried_from']?->translatedFormat('F Y');

            $rows[] = [
                'id' => $activity->id,
                'statement' => $activity->statement,
                'statuses' => ActivityStatus::allowedFor($activity->activity_kind ?? ActivityKind::Continuous),
                'entry_id' => $entry?->id,
                'status' => $entry?->status,
                'reason' => $entry?->reason,
                'started_on' => self::dateText($entry?->started_on),
                'completed_on' => self::dateText($entry?->completed_on),
                'executed_on' => self::dateText($entry?->executed_on),
                'note' => $entry?->note,
                'planned' => match (true) {
                    ! $row['scheduled'] => null,
                    $row['planned_weeks'] === null || $row['planned_weeks'] === [] => (string) __('monthly_reports.planned_this_month'),
                    default => trans_choice('monthly_reports.planned_weeks', count($row['planned_weeks']), ['weeks' => self::weeks($row['planned_weeks'])]),
                },
                'carried' => $carried === null ? null : (string) __($row['late'] ? 'monthly_reports.late_from' : 'monthly_reports.continuing_from', ['month' => $carried]),
                'late' => $row['late'],
                'files' => $entry === null ? [] : $this->fileRows(null, $entry->id),
            ];
        }

        return $rows;
    }

    /**
     * The narrative questions asked this month (contracts §13.1 item 3),
     * in MEAL's order.
     *
     * @return Collection<int, ReportTemplateQuestion>
     */
    #[Computed]
    public function questions(): Collection
    {
        return SaveMonthlyReportAnswers::questions($this->report)->get(['id', 'report_template_id', 'text_ar', 'text_en', 'frequency']);
    }

    /**
     * How far the viewer's part of the month is filled, and what is still
     * needed: the preparer's lines, activity statuses, answers and required
     * evidence on a draft; the validator's MEAL lines while submitted. Null
     * when the viewer has nothing to fill. Counts only; every figure comes
     * from the reads above.
     *
     * @return array{done: int, total: int, percent: int, needed: list<string>}|null
     */
    #[Computed]
    public function progress(): ?array
    {
        $report = $this->report;
        $rows = $this->rows;

        if ($report->status === MonthlyReportStatus::Submitted && $this->abilities['fillMealLines']) {
            $mealRows = array_filter($rows, fn (array $row): bool => $row['meal']);
            $filled = count(array_filter($mealRows, fn (array $row): bool => $row['filled']));
            $pending = count($mealRows) - $filled;

            return self::progressOf($filled, count($mealRows), $pending > 0 ? [trans_choice('monthly_reports.needed_meal_lines', $pending, ['count' => $pending])] : []);
        }

        if (! $this->fillsNarrative()) {
            return null;
        }

        $managerRows = array_filter($rows, fn (array $row): bool => ! $row['meal']);
        $filledRows = count(array_filter($managerRows, fn (array $row): bool => $row['filled']));
        $withStatus = count(array_filter($this->activities, fn (array $activity): bool => $activity['status'] !== null));
        $questionIds = $this->questions->modelKeys();
        $answered = $report->answers
            ->filter(fn (MonthlyReportAnswer $answer): bool => in_array($answer->report_template_question_id, $questionIds, true) && filled($answer->answer))
            ->count();
        $missingFiles = array_sum(array_map('count', $this->missingEvidence));

        $emptyRows = count($managerRows) - $filledRows;
        $noStatus = count($this->activities) - $withStatus;
        $needed = [];

        if ($emptyRows > 0) {
            $needed[] = trans_choice('monthly_reports.needed_indicators', $emptyRows, ['count' => $emptyRows]);
        }

        if ($missingFiles > 0) {
            $needed[] = trans_choice('monthly_reports.needed_files', $missingFiles, ['count' => $missingFiles]);
        }

        if ($noStatus > 0) {
            $needed[] = trans_choice('monthly_reports.needed_activities', $noStatus, ['count' => $noStatus]);
        }

        return self::progressOf(
            $filledRows + $withStatus + $answered,
            count($managerRows) + count($this->activities) + count($questionIds),
            $needed,
        );
    }

    /**
     * @param  list<string>  $needed
     * @return array{done: int, total: int, percent: int, needed: list<string>}
     */
    protected static function progressOf(int $done, int $total, array $needed): array
    {
        return [
            'done' => $done,
            'total' => $total,
            'percent' => $total === 0 ? 0 : (int) floor($done * 100 / $total),
            'needed' => $needed,
        ];
    }

    /**
     * Whether the viewer fills activities and answers: the preparer side on
     * a draft only.
     */
    protected function fillsNarrative(): bool
    {
        return $this->report->status === MonthlyReportStatus::Draft && $this->abilities['update'];
    }

    /**
     * The files of one line or one activity entry, as the page lists them.
     *
     * @return list<array{id: int, name: string, type: string, url: string}>
     */
    protected function fileRows(?int $lineId, ?int $entryId): array
    {
        $report = $this->report;

        return array_values($report->files
            ->filter(fn (MonthlyReportFile $file): bool => $lineId !== null
                ? $file->monthly_report_line_id === $lineId
                : $file->monthly_report_activity_entry_id === $entryId)
            ->map(fn (MonthlyReportFile $file): array => [
                'id' => $file->id,
                'name' => $file->original_name ?? basename($file->path),
                'type' => $file->evidenceType->localizedName(),
                'url' => route('monthly-reports.files.download', [$report, $file->id]),
            ])
            ->all());
    }

    /**
     * Forget every computed read so the page re-renders from the server.
     * Runs after every write and after a stale or refused one.
     */
    protected function forgetMonthlyReport(): void
    {
        unset(
            $this->report, $this->periods, $this->attribution, $this->abilities, $this->decidableRequest,
            $this->submitBlocker, $this->rows, $this->incompleteLines, $this->pendingMealLines,
            $this->missingEvidence, $this->evidenceTypes, $this->activities, $this->questions, $this->progress,
        );

        $this->renderedStatus = $this->report->status->value;
    }

    protected function renderedStatus(): MonthlyReportStatus
    {
        return MonthlyReportStatus::from($this->renderedStatus);
    }

    /**
     * "2", "2 and 3", "1, 2 and 4".
     *
     * @param  list<int>  $weeks
     */
    protected static function weeks(array $weeks): string
    {
        sort($weeks);
        $last = array_pop($weeks);

        return $weeks === [] ? (string) $last : implode(', ', $weeks).__('monthly_reports.weeks_and').$last;
    }

    protected static function dateText(mixed $date): ?string
    {
        return $date === null ? null : CarbonImmutable::parse($date)->toDateString();
    }

    /**
     * A stored figure as the page shows it: no trailing ".00".
     */
    protected static function figure(float $value): string
    {
        return number_format($value, fmod($value, 1.0) === 0.0 ? 0 : 2);
    }

    /**
     * The value the server computed, as text: a dash when empty, the Not
     * measured label when so marked.
     */
    protected static function computed(mixed $value, bool $notMeasured, string $unit): string
    {
        return match (true) {
            $notMeasured => __('monthly_reports.not_measured'),
            $value === null => '—',
            default => self::figure((float) $value).$unit,
        };
    }
}
