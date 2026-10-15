<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;
use App\Enums\TaskStatus;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

/**
 * What proposals/show says needs doing: the dated deadline list and the
 * warnings, most urgent first.
 *
 * Used by proposals/show only. It reads the page's #[Computed] proposal()
 * and the readings of ReadsProposalFunding, ReadsProposalDelivery and
 * ChartsProposalChronology; canViewAgreements() comes from
 * ChecksFinanceVisibility.
 *
 * @property-read Proposal $proposal  #[Computed] proposal()
 * @property-read Collection<int, DonorProposal> $agreements  #[Computed] agreements()
 * @property-read array<int, array{budget: float, received: float, pending: float, unscheduled: float, overdue: float, overdueCount: int, failedCount: int, foreignCount: int, missingNetCount: int, nextDue: CarbonImmutable|null}> $agreementMoney  #[Computed] agreementMoney()
 * @property-read array<string, array{committed: float, received: float, pending: float, unscheduled: float, percent: int, overdue: float, overdueCount: int, failedCount: int, nextDue: CarbonImmutable|null}> $moneyByCurrency  #[Computed] moneyByCurrency()
 * @property-read Collection<int, Project> $projects  #[Computed] projects()
 * @property-read Collection<int, Task> $tasks  #[Computed] tasks()
 * @property-read Collection<int, PlanIndicator> $targets  #[Computed] targets()
 * @property-read \Illuminate\Support\Collection<int, Report> $reports  #[Computed] reports()
 * @property-read Collection<int, DonorReport> $donorReports  #[Computed] donorReports()
 * @property-read array{start: CarbonImmutable, end: CarbonImmutable, days: int, dayIn: int, percent: float, state: string, untilStart: int, overBy: int} $term  #[Computed] term()
 * @property-read list<array{key: string, kind: string, date: CarbonImmutable, title: string, detail: string, state: string, days: int, url: string|null, tab: string}> $deadlines  #[Computed] deadlines()
 */
trait ReadsProposalHealth
{
    /** How many days ahead a deadline counts as due soon. */
    private const SOON_DAYS = 30;

    /**
     * Everything this grant still owes or is owed, on one dated list.
     *
     * Pending tranches, reports not yet submitted, open tasks, project ends
     * and the grant's own end. Finished items are left out — a deadline that
     * has been met is history, and the status and funding tabs hold it.
     * Undated pending tranches have no deadline and are counted elsewhere.
     *
     * @return list<array{key: string, kind: string, date: CarbonImmutable, title: string, detail: string, state: string, days: int, url: string|null, tab: string}>
     */
    #[Computed]
    public function deadlines(): array
    {
        $today = CarbonImmutable::today();
        $items = [];

        $push = function (string $key, string $kind, CarbonImmutable $date, string $title, string $detail, ?string $url, string $tab) use (&$items, $today): void {
            $days = (int) $today->diffInDays($date, false);

            $items[] = [
                'key' => $key,
                'kind' => $kind,
                'date' => $date,
                'title' => $title,
                'detail' => $detail,
                'state' => match (true) {
                    $days < 0 => 'overdue',
                    $days <= self::SOON_DAYS => 'soon',
                    default => 'later',
                },
                'days' => $days,
                'url' => $url,
                'tab' => $tab,
            ];
        };

        foreach ($this->agreements as $agreement) {
            foreach ($agreement->transfers as $transfer) {
                // Only money the donor has still to send is a deadline; a sent
                // tranche is in transit and waits on the bank, not the donor.
                if (! in_array($transfer->state(), ['late', 'upcoming'], true) || $transfer->scheduled_date === null) {
                    continue;
                }

                $push(
                    'transfer-'.$transfer->id,
                    'transfer',
                    $transfer->scheduled_date,
                    __('proposals.tranche_from_donor', ['donor' => $agreement->donor->name]),
                    number_format((float) $transfer->payment_amount, 2).' '.$transfer->amount_transfered_currency?->value,
                    route('funding-agreements.show', $agreement),
                    'funding',
                );
            }
        }

        foreach ($this->donorReports as $donorReport) {
            if ($donorReport->isSubmitted()) {
                continue;
            }

            $push(
                'donor-report-'.$donorReport->id,
                'donor-report',
                $donorReport->due_on,
                $donorReport->title,
                __('proposals.donor_report_for', ['donor' => $donorReport->donorProposal->donor->name, 'type' => $donorReport->type->label()]),
                route('funding-agreements.show', $donorReport->donorProposal),
                'funding',
            );
        }

        foreach ($this->reports as $report) {
            if (! $this->reportIsOwed($report)) {
                continue;
            }

            $push(
                'report-'.$report->id,
                'report',
                $report->planned_date,
                __('proposals.report_on_indicator', ['indicator' => $report->planIndicator->indicator->name]),
                $report->planIndicator->project?->name ?? __('common.organisation_wide'),
                route('reports.show', $report),
                'reports',
            );
        }

        foreach ($this->tasks as $task) {
            if ($task->status === TaskStatus::Done || $task->due_date === null) {
                continue;
            }

            $push(
                'task-'.$task->id,
                'task',
                $task->due_date,
                $task->title,
                $task->status->label(),
                route('tasks.show', $task),
                'tasks',
            );
        }

        foreach ($this->projects as $project) {
            if ($project->end_date === null || $project->end_date->lt($today)) {
                continue;
            }

            $push(
                'project-'.$project->id,
                'project',
                $project->end_date,
                __('proposals.project_ends', ['project' => $project->name]),
                $project->program->name,
                route('projects.show', $project),
                'projects',
            );
        }

        $grantEnd = $this->proposal->actual_date_end ?? $this->proposal->planned_date_end;

        if ($grantEnd !== null && $this->proposal->actual_date_end === null && ! in_array($this->proposal->status, [ProposalStatus::Completed, ProposalStatus::Rejected], true)) {
            $push('grant-end', 'grant', $grantEnd, __('proposals.grant_term_ends'), $this->proposal->name, null, $this->canViewAgreements() ? 'funding' : 'status');
        }

        usort($items, fn (array $a, array $b): int => [$a['date'], $a['key']] <=> [$b['date'], $b['key']]);

        return $items;
    }

    /**
     * Every problem on this grant worth a manager's attention, most urgent
     * first. Each names the tab that holds its evidence.
     *
     * @return list<array{key: string, text: string, tab: string, critical: bool}>
     */
    #[Computed]
    public function warnings(): array
    {
        $warnings = [];
        $isLive = ! in_array($this->proposal->status, [ProposalStatus::Rejected, ProposalStatus::Completed], true);

        foreach ($this->moneyByCurrency as $currency => $line) {
            if ($line['overdueCount'] > 0) {
                $warnings[] = [
                    'key' => 'overdue-money-'.$currency,
                    'text' => trans_choice('proposals.warning_overdue_tranches', $line['overdueCount'], [
                        'count' => $line['overdueCount'],
                        'amount' => number_format($line['overdue'], 2),
                        'currency' => $currency,
                    ]),
                    'tab' => 'funding',
                    'critical' => true,
                ];
            }

            if ($line['failedCount'] > 0) {
                $warnings[] = [
                    'key' => 'failed-money-'.$currency,
                    'text' => trans_choice('proposals.warning_failed_transfers', $line['failedCount'], ['count' => $line['failedCount'], 'currency' => $currency]),
                    'tab' => 'funding',
                    'critical' => true,
                ];
            }

            if ($isLive && $this->term['state'] !== 'before' && $line['unscheduled'] > 0.0) {
                $warnings[] = [
                    'key' => 'unscheduled-money-'.$currency,
                    'text' => __('proposals.warning_unscheduled_money', ['amount' => number_format($line['unscheduled'], 2), 'currency' => $currency]),
                    'tab' => 'funding',
                    'critical' => false,
                ];
            }
        }

        // Existence only, never an amount: a status corrected away from approved keeps its agreement.
        if (! $this->proposal->status->isApprovedByDonor() && DonorProposal::query()->where('proposal_id', $this->proposalId)->exists()) {
            $warnings[] = [
                'key' => 'agreement-status-inconsistent',
                'text' => __('funding_agreements.proposal_status_inconsistent_with_agreement'),
                'tab' => 'funding',
                'critical' => false,
            ];
        }

        $missingNet = collect($this->agreementMoney)->sum('missingNetCount');

        if ($missingNet > 0) {
            $warnings[] = [
                'key' => 'missing-net',
                'text' => trans_choice('funding_agreements.received_tranches_missing_net', $missingNet, ['count' => $missingNet]),
                'tab' => 'funding',
                'critical' => false,
            ];
        }

        $overdueDonorReports = collect($this->deadlines)->where('kind', 'donor-report')->where('state', 'overdue')->count();

        if ($overdueDonorReports > 0) {
            $warnings[] = [
                'key' => 'overdue-donor-reports',
                'text' => trans_choice('proposals.warning_overdue_donor_reports', $overdueDonorReports, ['count' => $overdueDonorReports]),
                'tab' => 'deadlines',
                'critical' => true,
            ];
        }

        $overdueReports = collect($this->deadlines)->where('kind', 'report')->where('state', 'overdue')->count();

        if ($overdueReports > 0) {
            $warnings[] = [
                'key' => 'overdue-reports',
                'text' => trans_choice('proposals.warning_overdue_reports', $overdueReports, ['count' => $overdueReports]),
                'tab' => 'reports',
                'critical' => true,
            ];
        }

        $overdueTasks = collect($this->deadlines)->where('kind', 'task')->where('state', 'overdue')->count();

        if ($overdueTasks > 0) {
            $warnings[] = [
                'key' => 'overdue-tasks',
                'text' => trans_choice('proposals.warning_overdue_tasks', $overdueTasks, ['count' => $overdueTasks]),
                'tab' => 'tasks',
                'critical' => true,
            ];
        }

        if ($isLive && $this->term['state'] === 'over') {
            $warnings[] = [
                'key' => 'term-over',
                'text' => trans_choice('proposals.warning_term_over', $this->term['overBy'], ['count' => $this->term['overBy']]),
                'tab' => 'status',
                'critical' => true,
            ];
        }

        $outrunning = $this->projects->filter(fn (Project $project): bool => $project->end_date !== null
            && $this->proposal->planned_date_end !== null
            && $project->end_date->gt($this->proposal->planned_date_end))->count();

        if ($outrunning > 0) {
            $warnings[] = [
                'key' => 'projects-outrun',
                'text' => trans_choice('proposals.warning_projects_outrun', $outrunning, ['count' => $outrunning]),
                'tab' => 'projects',
                'critical' => false,
            ];
        }

        $unreported = $this->targets->filter(fn (PlanIndicator $target): bool => $target->reports->isEmpty())->count();

        if ($isLive && $this->term['state'] !== 'before' && $unreported > 0) {
            $warnings[] = [
                'key' => 'unreported-targets',
                'text' => trans_choice('proposals.warning_unreported_targets', $unreported, ['count' => $unreported]),
                'tab' => 'targets',
                'critical' => false,
            ];
        }

        $brokenChains = $this->targets->filter(fn (PlanIndicator $target): bool => $target->chainIssues() !== [])->count();

        if ($brokenChains > 0) {
            $warnings[] = [
                'key' => 'broken-chains',
                'text' => trans_choice('proposals.warning_target_chain_issues', $brokenChains, ['count' => $brokenChains]),
                'tab' => 'targets',
                'critical' => false,
            ];
        }

        if ($this->canViewAgreements() && $this->agreements->isEmpty() && in_array($this->proposal->phase, [ProposalPhase::Implementation, ProposalPhase::Monitoring, ProposalPhase::Closure], true)) {
            $warnings[] = [
                'key' => 'no-agreement',
                'text' => __('proposals.warning_no_agreement'),
                'tab' => 'funding',
                'critical' => false,
            ];
        }

        usort($warnings, fn (array $a, array $b): int => $b['critical'] <=> $a['critical']);

        return $warnings;
    }
}
