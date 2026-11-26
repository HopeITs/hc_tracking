<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\Enums\ActiveReportType;
use App\Enums\DonorReportState;
use App\Enums\MonthlyReportStatus;
use App\Enums\ReportStatus;
use App\Models\AnnualPlan;
use App\Models\DonorReport;
use App\Models\MonthlyReport;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\ReportTemplate;
use App\Models\User;
use App\Support\DonorReportResponsibles;
use App\Support\MonthlyReportGenerator;
use App\Support\MonthlyReportNames;
use App\Support\MonthlyReportPeriods;
use App\Support\MonthlyReportPreparers;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Every report still open (or, on request, finished) that the viewer may open, from the three sources,
 * in one common row shape (M-02).
 *
 * Open means: a monthly report that is a draft (once its month has started)
 * or submitted; a donor report not yet submitted; an existing report that is
 * a draft or reviewed. A report mirror of a monthly report is that monthly
 * report, listed once as such.
 *
 * Who sees what is each source's own rule, never a new one: the monthly
 * reports of the templates the viewer prepares plus the ones they validate
 * (MonthlyReportPreparers, MonthlyReport::validatableBy), donor reports as
 * DonorReportPolicy::view decides, existing reports for reports.view. No
 * amount is read: money stays on the funding pages.
 *
 * A fixed number of queries whatever the number of reports.
 */
final class ActiveReports
{
    public function __construct(
        private readonly MonthlyReportPreparers $preparers,
        private readonly MonthlyReportNames $names,
    ) {}

    /**
     * Open reports: overdue first, then soonest due, then by name. With
     * `$closed` the finished ones instead (monthly validated, donor submitted,
     * report approved), the most recent first; none of them needs anyone.
     *
     * @return Collection<int, ActiveReportRow>
     */
    public function for(User $user, bool $closed = false): Collection
    {
        $rows = collect([...$this->monthly($user, $closed), ...$this->donor($user, $closed), ...$this->narrative($user, $closed)]);

        if ($closed) {
            return $rows
                ->sort(fn (ActiveReportRow $a, ActiveReportRow $b): int => [$b->due?->getTimestamp() ?? 0, mb_strtolower($a->name), $a->key()]
                    <=> [$a->due?->getTimestamp() ?? 0, mb_strtolower($b->name), $b->key()])
                ->values();
        }

        return $rows
            ->sort(fn (ActiveReportRow $a, ActiveReportRow $b): int => [! $a->overdue, $a->due === null, $a->due?->getTimestamp() ?? 0, mb_strtolower($a->name), $a->key()]
                <=> [! $b->overdue, $b->due === null, $b->due?->getTimestamp() ?? 0, mb_strtolower($b->name), $b->key()])
            ->values();
    }

    /**
     * @return list<ActiveReportRow>
     */
    private function monthly(User $user, bool $closed): array
    {
        $columns = ['id', 'slug', 'report_template_id', 'period', 'status', 'returned_at', 'revised_due_on'];
        $prepared = $this->preparers->templateIdsFor($user);

        $mine = MonthlyReport::query()
            ->whereIn('report_template_id', $prepared)
            ->whereIn('status', $closed ? [MonthlyReportStatus::Validated] : [MonthlyReportStatus::Draft, MonthlyReportStatus::Submitted])
            ->get($columns);

        $toValidate = MonthlyReport::query()
            ->validatableBy($user)
            ->where('status', $closed ? MonthlyReportStatus::Validated : MonthlyReportStatus::Submitted)
            ->get($columns);

        $validatable = $closed ? [] : array_flip($toValidate->pluck('id')->all());
        $preparedSet = array_flip($prepared);
        $reports = $mine->concat($toValidate)->unique('id')->values();

        if ($reports->isEmpty()) {
            return [];
        }

        $templates = ReportTemplate::query()
            ->whereKey($reports->pluck('report_template_id')->unique()->all())
            ->with(['proposals' => fn ($query) => $query->select([...MonthlyReportGenerator::WindowColumns, 'proposals.slug', 'proposals.name', 'proposals.internal_project_number', 'proposals.donor_id'])])
            ->get()
            ->keyBy('id');
        $workingDays = MonthlyReportPeriods::workingDaysOf($templates);
        $names = $this->names->forTemplates($templates);
        $responsibles = $this->preparers->responsiblesByTemplate($templates);
        $people = DonorReportResponsibles::resolve(array_values(array_unique(array_merge([], ...array_values($responsibles)))));
        $periods = [];
        $today = CarbonImmutable::today();
        $rows = [];

        foreach ($reports as $report) {
            $template = $templates->get($report->report_template_id);

            if ($template === null) {
                continue;
            }

            $periods[$template->id] ??= MonthlyReportPeriods::fromLoaded($template, new EloquentCollection, $workingDays[$template->id] ?? null);

            // A month that has not started is neither due nor open for entry.
            if (! $periods[$template->id]->hasStarted($report->period)) {
                continue;
            }

            $isDraft = $report->status === MonthlyReportStatus::Draft;
            $due = $periods[$template->id]->effectiveDueDate($report);
            $name = $names[$template->id];
            $proposalNames = $name['proposalsText'] !== '' ? $name['proposalsText'] : __('monthly_reports.no_proposal');
            $owners = collect($responsibles[$template->id] ?? [])
                ->map(fn (int $employeeId): ?string => $people[$employeeId]['employee']?->name)
                ->filter()
                ->implode(', ');

            $rows[] = new ActiveReportRow(
                type: ActiveReportType::Monthly,
                id: $report->id,
                name: $name['place'],
                context: $proposalNames,
                periodKey: $report->period->format('Y-m'),
                status: $report->status,
                due: $due,
                overdue: $isDraft && $today->greaterThan($due),
                owner: $owners === '' ? null : $owners,
                url: route('monthly-reports.show', $report),
                needsMe: ($isDraft && isset($preparedSet[$template->id])) || isset($validatable[$report->id]),
                programId: $template->program_id,
                donorId: $template->proposals->first()?->donor_id,
                search: mb_strtolower($name['place'].' '.$proposalNames),
            );
        }

        return $rows;
    }

    /**
     * Donor reports not yet submitted, by the rule of DonorReportPolicy::view.
     *
     * @return list<ActiveReportRow>
     */
    private function donor(User $user, bool $closed): array
    {
        $reports = DonorReport::query()
            ->when($closed, fn (Builder $query) => $query->whereNotNull('submitted_on'), fn (Builder $query) => $query->whereNull('submitted_on'))
            ->unless(
                $user->can('funding-agreements.view') || $user->can('reports.aggregate'),
                fn (Builder $query) => $query->where('responsible_employee_id', $user->employee_id ?? 0),
            )
            ->with(['donorProposal:id,proposal_id,donor_id', 'donorProposal.proposal:id,name,slug', 'donorProposal.donor:id,name'])
            ->get();

        if ($reports->isEmpty()) {
            return [];
        }

        $people = DonorReportResponsibles::resolve(array_values($reports->pluck('responsible_employee_id')->filter()->map(fn (mixed $id): int => (int) $id)->all()));

        return array_values($reports->map(function (DonorReport $report) use ($people, $user, $closed): ActiveReportRow {
            $agreement = $report->donorProposal;
            $proposalName = (string) $agreement?->proposal?->name;
            $donorName = (string) $agreement?->donor?->name;
            $state = DonorReportState::of($report);

            return new ActiveReportRow(
                type: ActiveReportType::Donor,
                id: $report->id,
                name: $report->title,
                context: $proposalName,
                periodKey: $report->isComposed() ? $report->period_start->format('Y-m') : null,
                status: $state,
                due: $report->due_on,
                overdue: $state === DonorReportState::Overdue,
                owner: $report->responsible_employee_id === null ? null : $people[$report->responsible_employee_id]['employee']?->name,
                url: route('donor-reports.show', $report),
                needsMe: ! $closed && $user->employee_id !== null && $report->responsible_employee_id === $user->employee_id,
                programId: null,
                donorId: $agreement?->donor_id,
                search: mb_strtolower($report->title.' '.$proposalName.' '.$donorName),
            );
        })->all());
    }

    /**
     * Existing (narrative) reports, never the mirrors of monthly reports.
     *
     * @return list<ActiveReportRow>
     */
    private function narrative(User $user, bool $closed): array
    {
        if ($user->cannot('viewAny', Report::class)) {
            return [];
        }

        $today = CarbonImmutable::today();
        $canApprove = $user->can('reports.approve');

        $rows = Report::query()
            ->whereNull('monthly_report_id')
            ->whereIn('status', $closed ? [ReportStatus::Approved] : [ReportStatus::Draft, ReportStatus::Reviewed])
            ->with([
                'planIndicator:id,indicator_id,source_type,source_id',
                'planIndicator.indicator:id,name',
                'planIndicator.source' => function (Relation $relation): void {
                    if ($relation instanceof MorphTo) {
                        $relation->morphWith([AnnualPlan::class => [], Proposal::class => []]);
                    }
                },
                'user:id,hr_user_id,employee_id',
            ])
            ->get(['id', 'slug', 'plan_indicator_id', 'user_id', 'status', 'planned_date'])
            ->map(function (Report $report) use ($user, $today, $canApprove, $closed): ActiveReportRow {
                $source = $report->planIndicator?->source;
                $context = match (true) {
                    $source instanceof AnnualPlan => __('common.fy_year', ['year' => $source->year]),
                    $source instanceof Proposal => $source->name,
                    default => __('targets.source_no_longer_available'),
                };
                $name = (string) $report->planIndicator?->indicator?->name;
                $isDraft = $report->status === ReportStatus::Draft;
                $isAuthor = $report->user_id === $user->id;

                return new ActiveReportRow(
                    type: ActiveReportType::Report,
                    id: $report->id,
                    name: $name,
                    context: $context,
                    periodKey: $report->planned_date->format('Y-m'),
                    status: $report->status,
                    due: CarbonImmutable::instance($report->planned_date),
                    overdue: $isDraft && $report->planned_date->lt($today),
                    owner: $report->user?->name,
                    url: route('reports.show', $report),
                    needsMe: ! $closed && ($isDraft ? $isAuthor : ($canApprove && ! $isAuthor)),
                    programId: null,
                    donorId: $source instanceof Proposal ? $source->donor_id : null,
                    search: mb_strtolower($name.' '.$context),
                );
            });

        return array_values($rows->all());
    }
}
