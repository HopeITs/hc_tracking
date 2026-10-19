<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ActivityStatus;
use App\Enums\DonorAttribution;
use App\Enums\Gender;
use App\Enums\IndicatorMeasure;
use App\Enums\MonthlyReportEntryKind;
use App\Enums\MonthlyReportStatus;
use App\Enums\ReportStatus;
use App\Enums\ReportTemplateLineStatus;
use App\Enums\ResultType;
use App\Models\DonorProposal;
use App\Models\LibraryIndicator;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportActivityEntry;
use App\Models\MonthlyReportAnswer;
use App\Models\MonthlyReportFile;
use App\Models\MonthlyReportLineBreakdown;
use App\Models\MonthlyReportLineShare;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\ReportTemplateLine;
use App\Models\Result;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One proposal's validated monthly figures for a period, as a donor report
 * snapshot (contracts §14, §14.1, §14.3; `schema_version` 1).
 *
 * Donor view only: figures come from the proposal's targets' share mirrors
 * (`reports.monthly_report_id` set, `status = approved`), so `split` already
 * holds this donor's share. Counts sum, a cumulative count takes its latest
 * value, and ratios pool only through PlanIndicator::pooledRatio(). Pending
 * months are listed under completeness and never counted.
 *
 * Read-only, with a query count that does not grow with centers or months.
 * Finance is queried only when the caller asks for it (a holder of
 * funding-agreements.view); otherwise the payload has no `finance` key.
 */
final class DonorReportAggregator
{
    private const ExecutionStatuses = [ActivityStatus::Started, ActivityStatus::Ongoing, ActivityStatus::Completed, ActivityStatus::Conducted];

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException self_funded
     */
    public function aggregate(Proposal $proposal, CarbonImmutable $start, CarbonImmutable $end, bool $includeFinance): array
    {
        $proposal->loadMissing(['donor', 'donorProposals', 'projects.program']);

        if ($proposal->donor->is_internal) {
            throw ValidationException::withMessages(['proposalId' => __('donor_reports.self_funded')]);
        }

        $start = $start->startOfMonth();
        $end = $end->startOfDay();
        $monthEnd = $end->endOfMonth()->startOfDay();
        $grantStart = ($proposal->actual_date_begin ?? $start)->startOfDay();
        $grantStart = $grantStart->gt($start) ? $start : $grantStart;
        /** @var Collection<int, Project> $centers */
        $centers = $proposal->projects->keyBy('id');
        $agreement = $proposal->donorProposals->first();

        $targets = $this->targets($proposal, $grantStart, $monthEnd);
        $shares = $this->shares($targets);
        $retired = $this->retiredTargetIds($targets);
        $reports = MonthlyReport::query()
            ->whereIn('project_id', $centers->keys())
            ->whereBetween('period', [$start->startOfMonth()->toDateString(), $end->toDateString()])
            ->orderBy('period')->orderBy('project_id')
            ->get(['id', 'project_id', 'period', 'status']);
        $validatedIds = array_values(array_map(fn (mixed $id): int => (int) $id, $reports->where('status', MonthlyReportStatus::Validated)->pluck('id')->all()));
        $breakdowns = $this->breakdowns($targets, $shares);

        $current = $targets->reject(fn (PlanIndicator $target): bool => in_array($target->id, $retired, true) || $target->indicator->library_indicator_id === null);
        $historical = $targets->diff($current)->filter(fn (PlanIndicator $target): bool => $target->getRelation('reports')->isNotEmpty());

        $context = ['start' => $start, 'end' => $monthEnd, 'grantStart' => $grantStart, 'centers' => $centers, 'shares' => $shares, 'breakdowns' => $breakdowns];
        $rows = $this->indicatorRows($current, $context);

        $snapshot = [
            'schema_version' => 1,
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'months' => array_map(fn (CarbonImmutable $month): string => $month->format('Y-m'), MonthlyReportGenerator::monthsBetween($start, $end)),
            ],
            'header' => $this->header($proposal, $agreement),
            'centers' => array_values($centers->map(fn (Project $project): array => [
                'project_id' => $project->id,
                'name' => $project->name,
                'center_id' => $project->center_id,
                'beneficiaries' => $this->beneficiaries($project),
            ])->all()),
            'indicators' => $rows['rows'],
            'historical' => $this->indicatorRows($historical, $context)['rows'],
            'activities' => $this->activities($proposal, $centers, $start, $end),
            'narratives' => $this->narratives($validatedIds, $reports),
            'files' => $this->files($validatedIds, $reports),
            'completeness' => [
                'missing' => array_values($reports
                    ->reject(fn (MonthlyReport $report): bool => $report->status === MonthlyReportStatus::Validated)
                    ->map(fn (MonthlyReport $report): array => [
                        'project_id' => $report->project_id,
                        'month' => $report->period->format('Y-m'),
                        'state' => $report->status === MonthlyReportStatus::Draft ? 'not_submitted' : 'not_validated',
                    ])->all()),
                'missing_denominator' => $rows['missing_denominator'],
                'has_validated_data' => $targets->contains(fn (PlanIndicator $target): bool => $target->getRelation('reports')
                    ->contains(fn (Report $report): bool => $report->planned_date->gte($start))),
            ],
        ];

        if ($includeFinance) {
            $snapshot['finance'] = $agreement === null ? [] : $this->finance($agreement, $start, $end);
        }

        return $snapshot;
    }

    /**
     * The proposal's targets with their approved share mirrors from the grant
     * start to the end of the period's last month (the cumulative window; the
     * period is a subset). A mirror is dated the last day of its month, so a
     * period clipped mid-month still counts that month.
     *
     * @return Collection<int, PlanIndicator>
     */
    private function targets(Proposal $proposal, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return PlanIndicator::query()
            ->where('source_type', 'proposal')
            ->where('source_id', $proposal->id)
            ->whereNotNull('project_id')
            ->with([
                'indicator.libraryIndicator',
                'reports' => fn (Relation $query) => $query
                    ->where('status', ReportStatus::Approved)
                    ->whereNotNull('monthly_report_id')
                    ->whereBetween('planned_date', [$from->toDateString(), $to->toDateString()])
                    ->select(['id', 'plan_indicator_id', 'monthly_report_id', 'monthly_report_line_share_id', 'status', 'planned_date', 'value', 'numerator', 'denominator']),
            ])
            ->orderBy('id')
            ->get(['id', 'source_type', 'source_id', 'indicator_id', 'project_id', 'target']);
    }

    /**
     * The mirrored shares: share id => line id, and how many shares each line
     * has (a `shared` line with another funding gets the footnote).
     *
     * @param  Collection<int, PlanIndicator>  $targets
     * @return array{line: array<int, int>, count: array<int, int>}
     */
    private function shares(Collection $targets): array
    {
        $shareIds = [];

        foreach ($targets as $target) {
            foreach ($target->getRelation('reports') as $report) {
                if ($report->monthly_report_line_share_id !== null) {
                    $shareIds[$report->monthly_report_line_share_id] = $report->monthly_report_line_share_id;
                }
            }
        }

        if ($shareIds === []) {
            return ['line' => [], 'count' => []];
        }

        $line = MonthlyReportLineShare::query()->whereKey(array_values($shareIds))->pluck('monthly_report_line_id', 'id')
            ->map(fn (mixed $lineId): int => (int) $lineId)->all();
        $count = DB::table('monthly_report_line_shares')
            ->whereIn('monthly_report_line_id', array_unique(array_values($line)))
            ->groupBy('monthly_report_line_id')
            ->selectRaw('monthly_report_line_id, count(*) as shares')
            ->pluck('shares', 'monthly_report_line_id')
            ->map(fn (mixed $shares): int => (int) $shares)->all();

        return ['line' => $line, 'count' => $count];
    }

    /**
     * Targets whose report-template line was retired: they left the proposal
     * after reports existed and go in the historical section.
     *
     * @param  Collection<int, PlanIndicator>  $targets
     * @return list<int>
     */
    private function retiredTargetIds(Collection $targets): array
    {
        if ($targets->isEmpty()) {
            return [];
        }

        return array_values(ReportTemplateLine::query()
            ->whereIn('plan_indicator_id', $targets->pluck('id')->all())
            ->where('status', ReportTemplateLineStatus::Retired)
            ->distinct()
            ->pluck('plan_indicator_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * The by-sex rows of the mirrored shares (`split`) and of their lines
     * (`shared`, stored with no share).
     *
     * @param  Collection<int, PlanIndicator>  $targets
     * @param  array{line: array<int, int>, count: array<int, int>}  $shares
     * @return Collection<int, MonthlyReportLineBreakdown>
     */
    private function breakdowns(Collection $targets, array $shares): Collection
    {
        $disaggregated = $targets->contains(fn (PlanIndicator $target): bool => (bool) $target->indicator->libraryIndicator?->is_disaggregated);

        if (! $disaggregated || $shares['line'] === []) {
            return new Collection;
        }

        return MonthlyReportLineBreakdown::query()
            ->where(fn (Builder $query) => $query
                ->whereIn('monthly_report_line_share_id', array_keys($shares['line']))
                ->orWhere(fn (Builder $query) => $query->whereNull('monthly_report_line_share_id')->whereIn('monthly_report_line_id', array_unique(array_values($shares['line'])))))
            ->get();
    }

    /**
     * One row per indicator, with a breakdown per center (one target each).
     *
     * @param  Collection<int, PlanIndicator>  $targets
     * @param  array<string, mixed>  $context
     * @return array{rows: list<array<string, mixed>>, missing_denominator: list<array{plan_indicator_id: int, project_id: int}>}
     */
    private function indicatorRows(Collection $targets, array $context): array
    {
        $rows = [];
        $missing = [];

        foreach ($targets->groupBy('indicator_id') as $group) {
            $indicator = $group->first()->indicator;
            $library = $indicator->library_indicator_id === null ? null : $indicator->libraryIndicator;
            $isRatio = $indicator->measure !== IndicatorMeasure::Numeric;
            $cumulative = (bool) $library?->is_cumulative;
            $trackingOnly = $indicator->is_tracking_only;
            $centers = [];

            foreach ($group as $target) {
                $period = $this->figure($target, $isRatio, $cumulative, $context['start'], $context['end']);
                $toDate = $this->figure($target, $isRatio, $cumulative, $context['grantStart'], $context['end']);

                if ($period['excluded'] > 0) {
                    $missing[] = ['plan_indicator_id' => $target->id, 'project_id' => (int) $target->project_id];
                }

                $centers[] = [
                    'plan_indicator_id' => $target->id,
                    'project_id' => (int) $target->project_id,
                    'target' => (float) $target->target,
                    'beneficiaries' => $this->beneficiaries($context['centers']->get($target->project_id)),
                    'period' => $period['figure'],
                    'cumulative' => $toDate['figure'],
                    'achievement' => $trackingOnly ? null : $this->achievement($toDate['figure']['value'], (float) $target->target),
                    'excluded' => $period['excluded'],
                    'shared' => $this->isShared($target, $context),
                ];
            }

            $rowTarget = $this->rowTarget($centers, $isRatio);
            $rowPeriod = $isRatio ? $this->pooled($group, $context['start'], $context['end']) : $this->sumFigures(array_column($centers, 'period'));
            $rowToDate = $isRatio ? $this->pooled($group, $context['grantStart'], $context['end']) : $this->sumFigures(array_column($centers, 'cumulative'));

            $rows[] = [
                'indicator_id' => $indicator->id,
                'library_indicator_id' => $indicator->library_indicator_id,
                'code' => $library->code ?? $indicator->code,
                'name' => $indicator->name,
                'measure' => $indicator->measure->value,
                'unit' => $library->unit ?? $indicator->unit,
                'basis' => $library?->denominator_basis?->value,
                'is_cumulative' => $cumulative,
                'is_tracking_only' => $trackingOnly,
                'shared' => in_array(true, array_column($centers, 'shared'), true),
                'target' => $rowTarget,
                'period' => $rowPeriod,
                'cumulative' => $rowToDate,
                'achievement' => $trackingOnly || $rowTarget === null ? null : $this->achievement($rowToDate['value'], $rowTarget),
                'excluded' => array_sum(array_column($centers, 'excluded')),
                'centers' => $centers,
                'disaggregation' => $library !== null && $library->is_disaggregated ? $this->disaggregation($group, $library, $context) : null,
            ];
        }

        return ['rows' => $rows, 'missing_denominator' => $missing];
    }

    /**
     * One target's figure in a window: a count (sum, or the latest value when
     * cumulative) or a pooled ratio.
     *
     * @return array{figure: array{value: ?float, numerator: ?float, denominator: ?float}, excluded: int}
     */
    private function figure(PlanIndicator $target, bool $isRatio, bool $cumulative, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($isRatio) {
            $pooled = PlanIndicator::pooledRatio([$target], $from, $to);

            return ['figure' => ['value' => $pooled['ratio'], 'numerator' => $pooled['numerator'], 'denominator' => $pooled['denominator']], 'excluded' => $pooled['excluded']];
        }

        $values = $this->inWindow($target, $from, $to)->filter(fn (Report $report): bool => $report->value !== null);

        $value = match (true) {
            $values->isEmpty() => null,
            $cumulative => (float) $values->sortBy([['planned_date', 'desc'], ['id', 'desc']])->first()->value,
            default => (float) $values->sum(fn (Report $report): float => (float) $report->value),
        };

        return ['figure' => ['value' => $value, 'numerator' => null, 'denominator' => null], 'excluded' => 0];
    }

    /**
     * Σ numerators ÷ Σ denominators across the indicator's centers.
     *
     * @param  Collection<int, PlanIndicator>  $targets
     * @return array{value: ?float, numerator: ?float, denominator: ?float}
     */
    private function pooled(Collection $targets, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $pooled = PlanIndicator::pooledRatio($targets, $from, $to);

        return ['value' => $pooled['ratio'], 'numerator' => $pooled['numerator'], 'denominator' => $pooled['denominator']];
    }

    /**
     * Center counts summed into the indicator's count.
     *
     * @param  list<array{value: ?float, numerator: ?float, denominator: ?float}>  $figures
     * @return array{value: ?float, numerator: null, denominator: null}
     */
    private function sumFigures(array $figures): array
    {
        $values = array_filter(array_column($figures, 'value'), fn (?float $value): bool => $value !== null);

        return ['value' => $values === [] ? null : (float) array_sum($values), 'numerator' => null, 'denominator' => null];
    }

    /**
     * The indicator's target: the sum of the center targets for a count; for
     * a ratio, the one target every center shares (a percentage is never
     * added), else none.
     *
     * @param  list<array<string, mixed>>  $centers
     */
    private function rowTarget(array $centers, bool $isRatio): ?float
    {
        $targets = array_column($centers, 'target');

        if (! $isRatio) {
            return (float) array_sum($targets);
        }

        return count(array_unique(array_map('strval', $targets))) === 1 ? (float) $targets[0] : null;
    }

    /**
     * Achieved ÷ target × 100, as PlanIndicator::percentAchieved().
     */
    private function achievement(?float $achieved, float $target): ?float
    {
        return $target > 0.0 ? ($achieved ?? 0.0) / $target * 100 : null;
    }

    /**
     * @return Collection<int, Report>
     */
    private function inWindow(PlanIndicator $target, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $target->getRelation('reports')
            ->filter(fn (Report $report): bool => $report->planned_date->betweenIncluded($from->startOfDay(), $to->endOfDay()));
    }

    /**
     * A `shared` program's figure that another funding also counts (its line
     * has more than one share).
     *
     * @param  array<string, mixed>  $context
     */
    private function isShared(PlanIndicator $target, array $context): bool
    {
        $project = $context['centers']->get($target->project_id);

        if ($project?->program?->donor_attribution !== DonorAttribution::Shared) {
            return false;
        }

        return $target->getRelation('reports')->contains(function (Report $report) use ($context): bool {
            $lineId = $context['shares']['line'][$report->monthly_report_line_share_id] ?? null;

            return $lineId !== null && ($context['shares']['count'][$lineId] ?? 0) > 1;
        });
    }

    /**
     * Male, of whom PwD, female, of whom PwD and total (D-50), per center and
     * for the proposal. Per target: counts sum over the period (the latest
     * month when cumulative); entered ratios take the latest month; the
     * centers then add up. Fixed-denominator indicators show counts only.
     *
     * @param  Collection<int, PlanIndicator>  $targets
     * @param  array<string, mixed>  $context
     * @return array{centers: list<array<string, mixed>>, total: array<string, array<string, float|null>>}|null
     */
    private function disaggregation(Collection $targets, LibraryIndicator $library, array $context): ?array
    {
        $kind = MonthlyReportEntryKind::for($library);
        $multiplier = MonthlyReportEntryKind::multiplierFor($library);
        $centers = [];
        $total = [];

        foreach ($targets as $target) {
            $mirrors = $this->inWindow($target, $context['start'], $context['end'])->sortBy([['planned_date', 'desc'], ['id', 'desc']]);

            if ($kind === MonthlyReportEntryKind::EnteredRatio || $library->is_cumulative) {
                $mirrors = $mirrors->take(1);
            }

            $split = $context['centers']->get($target->project_id)?->program?->donor_attribution === DonorAttribution::Split;
            $sums = [];

            foreach ($mirrors as $mirror) {
                $shareId = $mirror->monthly_report_line_share_id;
                $lineId = $context['shares']['line'][$shareId] ?? null;
                $rows = $context['breakdowns']->filter(fn (MonthlyReportLineBreakdown $row): bool => $split
                    ? $row->monthly_report_line_share_id === $shareId
                    : $row->monthly_report_line_share_id === null && $row->monthly_report_line_id === $lineId);

                foreach ($rows as $row) {
                    $this->addBreakdown($sums, $row, $kind);
                }
            }

            if ($sums === []) {
                continue;
            }

            foreach ($sums as $category => $parts) {
                foreach ($parts as $part => $amount) {
                    $total[$category][$part] = ($total[$category][$part] ?? 0.0) + $amount;
                }
            }

            $centers[] = ['project_id' => (int) $target->project_id, ...$this->categories($sums, $kind, $multiplier)];
        }

        return $centers === [] ? null : ['centers' => $centers, 'total' => $this->categories($total, $kind, $multiplier)];
    }

    /**
     * @param  array<string, array<string, float>>  $sums
     */
    private function addBreakdown(array &$sums, MonthlyReportLineBreakdown $row, MonthlyReportEntryKind $kind): void
    {
        $gender = $row->gender === Gender::Male ? 'male' : 'female';
        $fields = match ($kind) {
            MonthlyReportEntryKind::Count => ['value' => 'value'],
            MonthlyReportEntryKind::FixedRatio => ['value' => 'numerator'],
            MonthlyReportEntryKind::EnteredRatio => ['numerator' => 'numerator', 'denominator' => 'denominator'],
        };

        foreach ($fields as $part => $column) {
            $sums[$gender][$part] = ($sums[$gender][$part] ?? 0.0) + (float) $row->{$column};
            $sums["{$gender}_disabled"][$part] = ($sums["{$gender}_disabled"][$part] ?? 0.0) + (float) $row->{"disabled_{$column}"};
        }
    }

    /**
     * The five categories, with the total = male + female.
     *
     * @param  array<string, array<string, float>>  $sums
     * @return array<string, array<string, float|null>>
     */
    private function categories(array $sums, MonthlyReportEntryKind $kind, int $multiplier): array
    {
        $parts = $kind === MonthlyReportEntryKind::EnteredRatio ? ['numerator', 'denominator'] : ['value'];
        $categories = [];

        foreach (['male', 'male_disabled', 'female', 'female_disabled', 'total'] as $category) {
            $figure = [];

            foreach ($parts as $part) {
                $figure[$part] = $category === 'total'
                    ? ($sums['male'][$part] ?? 0.0) + ($sums['female'][$part] ?? 0.0)
                    : $sums[$category][$part] ?? 0.0;
            }

            if ($kind === MonthlyReportEntryKind::EnteredRatio) {
                $denominator = $figure['denominator'] ?? 0.0;
                $figure['value'] = $denominator > 0.0 ? ($figure['numerator'] ?? 0.0) / $denominator * $multiplier : null;
            }

            $categories[$category] = $figure;
        }

        return $categories;
    }

    /**
     * Each activity's status at period end per center (its latest validated
     * entry on or before the end) and the months in the period it was being
     * carried out (started, ongoing, completed or conducted).
     *
     * @param  Collection<int, Project>  $centers
     * @return list<array<string, mixed>>
     */
    private function activities(Proposal $proposal, Collection $centers, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $activities = Result::query()
            ->where('proposal_id', $proposal->id)
            ->where('type', ResultType::Activity)
            ->orderBy('id')
            ->get(['id', 'statement']);

        if ($activities->isEmpty() || $centers->isEmpty()) {
            return [];
        }

        $entries = MonthlyReportActivityEntry::query()
            ->join('monthly_reports', 'monthly_reports.id', '=', 'monthly_report_activity_entries.monthly_report_id')
            ->whereIn('monthly_report_activity_entries.result_id', $activities->modelKeys())
            ->whereIn('monthly_reports.project_id', $centers->keys())
            ->where('monthly_reports.status', MonthlyReportStatus::Validated)
            ->where('monthly_reports.period', '<=', $end->toDateString())
            ->whereNotNull('monthly_report_activity_entries.status')
            ->orderBy('monthly_reports.period')
            ->get(['monthly_report_activity_entries.result_id', 'monthly_report_activity_entries.status', 'monthly_reports.project_id', 'monthly_reports.period']);

        return array_values($activities->map(function (Result $activity) use ($entries, $start): array {
            $rows = [];

            foreach ($entries->where('result_id', $activity->id)->groupBy('project_id') as $projectId => $projectEntries) {
                $rows[] = [
                    'project_id' => (int) $projectId,
                    'status' => $projectEntries->last()->status->value,
                    'execution_months' => array_values($projectEntries
                        ->filter(fn (MonthlyReportActivityEntry $entry): bool => CarbonImmutable::parse($entry->getAttribute('period'))->gte($start->startOfMonth())
                            && in_array($entry->status, self::ExecutionStatuses, true))
                        ->map(fn (MonthlyReportActivityEntry $entry): string => CarbonImmutable::parse($entry->getAttribute('period'))->format('Y-m'))
                        ->unique()
                        ->all()),
                ];
            }

            return ['result_id' => $activity->id, 'statement' => $activity->statement, 'centers' => $rows];
        })->all());
    }

    /**
     * Answers of validated reports: the question as it was asked, and the
     * answer, per center per month.
     *
     * @param  list<int>  $validatedIds
     * @param  Collection<int, MonthlyReport>  $reports
     * @return list<array{project_id: int, month: string, question: string, answer: string}>
     */
    private function narratives(array $validatedIds, Collection $reports): array
    {
        if ($validatedIds === []) {
            return [];
        }

        $byId = $reports->keyBy('id');

        return array_values(MonthlyReportAnswer::query()
            ->whereIn('monthly_report_id', $validatedIds)
            ->whereNotNull('answer')
            ->orderBy('monthly_report_id')->orderBy('id')
            ->get(['id', 'monthly_report_id', 'question_text', 'answer'])
            ->map(fn (MonthlyReportAnswer $answer): array => [
                'project_id' => $byId[$answer->monthly_report_id]->project_id,
                'month' => $byId[$answer->monthly_report_id]->period->format('Y-m'),
                'question' => $answer->question_text,
                'answer' => (string) $answer->answer,
            ])->all());
    }

    /**
     * Links to the validated reports' evidence files (never copies).
     *
     * @param  list<int>  $validatedIds
     * @param  Collection<int, MonthlyReport>  $reports
     * @return list<array<string, mixed>>
     */
    private function files(array $validatedIds, Collection $reports): array
    {
        if ($validatedIds === []) {
            return [];
        }

        $byId = $reports->keyBy('id');

        return array_values(MonthlyReportFile::query()
            ->whereIn('monthly_report_id', $validatedIds)
            ->with('evidenceType:id,name_ar,name_en')
            ->orderBy('monthly_report_id')->orderBy('id')
            ->get(['id', 'monthly_report_id', 'evidence_type_id', 'original_name'])
            ->map(fn (MonthlyReportFile $file): array => [
                'id' => $file->id,
                'monthly_report_id' => $file->monthly_report_id,
                'project_id' => $byId[$file->monthly_report_id]->project_id,
                'month' => $byId[$file->monthly_report_id]->period->format('Y-m'),
                'name' => $file->original_name,
                'evidence_type_id' => $file->evidence_type_id,
                'evidence_type_ar' => $file->evidenceType->name_ar,
                'evidence_type_en' => $file->evidenceType->name_en,
            ])->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function header(Proposal $proposal, ?DonorProposal $agreement): array
    {
        return [
            'proposal_id' => $proposal->id,
            'proposal_name' => $proposal->name,
            'internal_number' => $proposal->internal_project_number,
            'donor_id' => $proposal->donor_id,
            'donor_name' => $proposal->donor->name,
            'agreement_id' => $agreement?->id,
            'contract_number' => $agreement?->external_project_number,
            'implementer' => config('app.name'),
            'goal' => Result::query()
                ->where('proposal_id', $proposal->id)
                ->where('type', ResultType::Impact)
                ->orderBy('id')
                ->value('statement'),
        ];
    }

    private function beneficiaries(?Project $project): ?int
    {
        $value = $project?->getRelationValue('pivot')?->getAttribute('target_beneficiaries');

        return $value === null ? null : (int) $value;
    }

    /**
     * Money received in the period, one line per received currency, with the
     * fees per fee currency. Currencies are never added together; net counts
     * only recorded net amounts (the rest is `net_missing`).
     *
     * @return list<array{currency: string, received: float, net: float, net_missing: int, fees: list<array{currency: string, amount: float}>}>
     */
    private function finance(DonorProposal $agreement, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = TransfersTracking::query()
            ->where('donor_proposal_id', $agreement->id)
            ->inState('received')
            ->whereBetween('receive_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('amount_received_currency', 'fees_currency')
            ->orderBy('amount_received_currency')->orderBy('fees_currency')
            ->toBase()
            ->selectRaw('amount_received_currency as currency, fees_currency, coalesce(sum(amount_received), 0) as received, coalesce(sum(net_amount_received), 0) as net, sum(case when net_amount_received is null then 1 else 0 end) as net_missing, coalesce(sum(fees), 0) as fees')
            ->get();

        $lines = [];

        foreach ($rows as $row) {
            $currency = (string) $row->currency;
            $lines[$currency] ??= ['currency' => $currency, 'received' => 0.0, 'net' => 0.0, 'net_missing' => 0, 'fees' => []];
            $lines[$currency]['received'] += (float) $row->received;
            $lines[$currency]['net'] += (float) $row->net;
            $lines[$currency]['net_missing'] += (int) $row->net_missing;
            $lines[$currency]['fees'][] = ['currency' => (string) $row->fees_currency, 'amount' => (float) $row->fees];
        }

        return array_values($lines);
    }
}
