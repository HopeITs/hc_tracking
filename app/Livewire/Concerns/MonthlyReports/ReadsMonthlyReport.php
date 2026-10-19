<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MonthlyReports;

use App\Enums\DonorAttribution;
use App\Enums\IndicatorCollector;
use App\Enums\IndicatorMeasure;
use App\Enums\MonthlyReportEntryKind;
use App\Enums\MonthlyReportStatus;
use App\Enums\ReopenRequestStatus;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLine;
use App\Models\MonthlyReportLineShare;
use App\Models\MonthlyReportReopenRequest;
use App\Models\Proposal;
use App\Support\MonthlyReportLineBuilder;
use App\Support\MonthlyReportPeriods;
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
     * Forget every computed read so the page re-renders from the server.
     * Runs after every write and after a stale or refused one.
     */
    protected function forgetMonthlyReport(): void
    {
        unset(
            $this->report, $this->periods, $this->attribution, $this->abilities, $this->decidableRequest,
            $this->submitBlocker, $this->rows, $this->incompleteLines, $this->pendingMealLines,
        );

        $this->renderedStatus = $this->report->status->value;
    }

    protected function renderedStatus(): MonthlyReportStatus
    {
        return MonthlyReportStatus::from($this->renderedStatus);
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
