<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\Enums\ActiveReportType;
use App\Enums\DonorReportState;
use App\Enums\MonthlyReportStatus;
use App\Models\DonorReport;
use App\Models\MonthlyReport;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Every report still open (or, on request, finished) that the viewer may open, from the two sources,
 * in one common row shape (M-02).
 *
 * Open means: a monthly report that is a draft (once its month has started)
 * or submitted; a donor report not yet submitted. Hand-recorded reports are
 * not tracked here (2026-11-26): every target is reported through monthly
 * reports, so they stay on the Reports page only.
 *
 * Who sees what is each source's own rule, never a new one: the monthly
 * reports of the templates the viewer prepares plus the ones they validate
 * (MonthlyReportPreparers, MonthlyReport::validatableBy), donor reports as
 * DonorReportPolicy::view decides. No
 * amount is read: money stays on the funding pages.
 *
 * A fixed number of queries whatever the number of reports. The rows are
 * kept in the cache per viewer, day and locale for a few minutes, so every
 * click on the Report Tracker answers from memory; saving or deleting any
 * record the rows are read from forgets them all at once (forget()).
 */
final class ActiveReports
{
    private const VersionKey = 'active-reports.version';

    private const KeepSeconds = 300;

    public function __construct(
        private readonly MonthlyReportPreparers $preparers,
        private readonly MonthlyReportNames $names,
    ) {}

    /**
     * Open reports: overdue first, then soonest due, then by name. With
     * `$closed` the finished ones instead (monthly validated, donor submitted), the most recent first; none of them needs anyone.
     *
     * @return Collection<int, ActiveReportRow>
     */
    public function for(User $user, bool $closed = false): Collection
    {
        $key = sprintf('active-reports-rows:%s:%d:%s:%s:%s', Cache::get(self::VersionKey, '0'), $user->id, $closed ? 'closed' : 'open', CarbonImmutable::today()->format('Y-m-d'), app()->getLocale());

        $cached = Cache::remember($key, self::KeepSeconds, fn (): array => $this->build($user, $closed)->map(fn (ActiveReportRow $row): array => $row->toCache())->all());

        if (! is_array($cached) || ($cached !== [] && ! is_array(reset($cached)))) {
            Cache::forget($key);

            return $this->build($user, $closed);
        }

        return collect($cached)->map(fn (array $row): ActiveReportRow => ActiveReportRow::fromCache($row))->values();
    }

    /**
     * Forget every viewer's cached rows: a report, template, place or agreement changed.
     */
    public static function forget(): void
    {
        Cache::forever(self::VersionKey, (string) hrtime(true));
    }

    /**
     * @return Collection<int, ActiveReportRow>
     */
    private function build(User $user, bool $closed): Collection
    {
        $rows = collect([...$this->monthly($user, $closed), ...$this->donor($user, $closed)]);

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
                templateId: $template->id,
                returned: $isDraft && $report->returned_at !== null,
                needsCheck: isset($validatable[$report->id]),
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
                donorName: $donorName === '' ? null : $donorName,
            );
        })->all());
    }
}
