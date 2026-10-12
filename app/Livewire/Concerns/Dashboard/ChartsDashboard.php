<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Dashboard;

use App\Enums\ArStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalPhase;
use App\Enums\ProposalStatus;
use App\Models\DonorProposal;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\TransfersTracking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * The dashboard's figures and charts: the funding position, the portfolio
 * pulse, the projects-by-status donut, the proposal pipeline and the monthly
 * receipts.
 *
 * Used by dashboard/index only. The page provides visible(), canView() and
 * canViewFundingPosition(). pulse() reads attentionCounts() from
 * ReadsDashboardAttention.
 *
 * @property-read array<string, int> $attentionCounts  #[Computed] attentionCounts() in ReadsDashboardAttention
 */
trait ChartsDashboard
{
    /**
     * Money committed in funding agreements against money received, per currency.
     *
     * USD, EUR and SYP are not convertible in this system, so each currency is
     * its own line and nothing is ever summed across them.
     *
     * @return Collection<int, array{currency: string, committed: float, received: float, pending: float, pendingCount: int, share: int|null}>
     */
    #[Computed]
    public function fundingPosition(): Collection
    {
        if (! $this->canViewFundingPosition()) {
            return new Collection;
        }

        $committed = DonorProposal::query()
            ->selectRaw('currency, sum(budget) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency');

        $received = TransfersTracking::query()
            ->where('ar_status', ArStatus::Received)
            ->selectRaw('amount_received_currency as currency, sum(net_amount_received) as total')
            ->groupBy('amount_received_currency')
            ->pluck('total', 'currency');

        $pending = TransfersTracking::query()
            ->where('ar_status', ArStatus::Pending)
            ->selectRaw('amount_transfered_currency as currency, sum(payment_amount) as total, count(*) as transfers')
            ->groupBy('amount_transfered_currency')
            ->get()
            ->keyBy('currency');

        return $committed->keys()
            ->concat($received->keys())
            ->concat($pending->keys())
            ->map(fn ($currency): string => (string) $currency)
            ->unique()
            ->sort()
            ->values()
            ->map(function (string $currency) use ($committed, $received, $pending): array {
                $committedTotal = (float) ($committed[$currency] ?? 0);
                $receivedTotal = (float) ($received[$currency] ?? 0);

                return [
                    'currency' => $currency,
                    'committed' => $committedTotal,
                    'received' => $receivedTotal,
                    'pending' => (float) ($pending[$currency]->total ?? 0),
                    'pendingCount' => (int) ($pending[$currency]->transfers ?? 0),
                    // Not capped: money received beyond the commitment must show as over 100%.
                    'share' => $committedTotal > 0 ? (int) floor($receivedTotal / $committedTotal * 100) : null,
                ];
            });
    }

    /**
     * The four portfolio pulse figures.
     *
     * @return array<int, array{key: string, label: string, value: int, denominator: int|null, tone: string, href: string}>
     */
    #[Computed]
    public function pulse(): array
    {
        $materialisedRisks = $this->attentionCounts['risks'];
        // Counted exactly as the reports page filters ?seenByMeal=unseen, so the
        // figure matches the list it links to.
        $awaitingReview = $this->visible(Report::query(), 'reports')->where('is_seen_by_meal', false)->count();

        return array_values(array_filter([
            [
                'key' => 'active-projects',
                'label' => __('dashboard.active_projects'),
                'value' => $this->visible(Project::query(), 'projects')->where('status', ProjectStatus::Active)->count(),
                'denominator' => $this->visible(Project::query(), 'projects')->count(),
                'tone' => 'neutral',
                'href' => route('projects.index', ['status' => ProjectStatus::Active->value]),
            ],
            ! $this->canView('proposals') ? null : [
                'key' => 'open-proposals',
                'label' => __('dashboard.proposals_in_pipeline'),
                'value' => Proposal::query()->whereNotIn('status', [ProposalStatus::Completed, ProposalStatus::Rejected])->count(),
                'denominator' => null,
                'tone' => 'funding',
                'href' => route('proposals.index'),
            ],
            [
                'key' => 'materialised-risks',
                'label' => __('dashboard.materialised_risks'),
                'value' => $materialisedRisks,
                'denominator' => null,
                'tone' => $materialisedRisks > 0 ? 'critical' : 'neutral',
                'href' => route('risks.index', ['status' => 'happened']),
            ],
            [
                'key' => 'awaiting-review',
                'label' => __('dashboard.reports_awaiting_meal_review'),
                'value' => $awaitingReview,
                'denominator' => null,
                'tone' => $awaitingReview > 0 ? 'warn' : 'neutral',
                'href' => route('reports.index', ['seenByMeal' => 'unseen']),
            ],
        ]));
    }

    /**
     * Projects counted by status, in lifecycle order.
     *
     * @return array{series: array<int, int>, categories: array<int, string>, tones: array<int, string>}
     */
    #[Computed]
    public function projectStatusChart(): array
    {
        $counts = $this->visible(Project::query(), 'projects')->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $tones = [
            ProjectStatus::Draft->value => 'muted',
            ProjectStatus::Submitted->value => 'neutral',
            ProjectStatus::Approved->value => 'funding',
            ProjectStatus::Active->value => 'good',
            ProjectStatus::Closed->value => 'strong',
            ProjectStatus::Archived->value => 'warn',
        ];

        return [
            'series' => array_map(fn (ProjectStatus $status): int => (int) ($counts[$status->value] ?? 0), ProjectStatus::cases()),
            'categories' => array_map(fn (ProjectStatus $status): string => $status->label(), ProjectStatus::cases()),
            'tones' => array_values($tones),
        ];
    }

    /**
     * Proposals counted by phase of work.
     *
     * @return array{series: array<int, array{name: string, data: array<int, int>}>, categories: array<int, string>}
     */
    #[Computed]
    public function pipelineChart(): array
    {
        if (! $this->canView('proposals')) {
            return ['series' => [], 'categories' => []];
        }

        $byStatus = Proposal::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        // Only open proposals, matching the "Proposals in pipeline" figure: a
        // rejected or completed proposal is no longer in the pipeline, which also
        // leaves the Archived phase (Completed only) with nothing to count.
        $closed = [ProposalStatus::Rejected, ProposalStatus::Completed];
        $openStatuses = fn (ProposalPhase $phase): array => array_values(array_filter($phase->statuses(), fn (ProposalStatus $status): bool => ! in_array($status, $closed, true)));
        $phases = array_values(array_filter(ProposalPhase::cases(), fn (ProposalPhase $phase): bool => $openStatuses($phase) !== []));

        return [
            'series' => [[
                'name' => __('dashboard.proposals'),
                'data' => array_map(fn (ProposalPhase $phase): int => (int) collect($openStatuses($phase))->sum(fn (ProposalStatus $status): int => (int) ($byStatus[$status->value] ?? 0)), $phases),
            ]],
            'categories' => array_map(fn (ProposalPhase $phase): string => $phase->label(), $phases),
        ];
    }

    /**
     * Net money received per month over the last twelve months, one series per currency.
     *
     * @return array{categories: array<int, string>, currencies: array<int, array{currency: string, data: array<int, float>, total: float}>}
     */
    #[Computed]
    public function receiptsChart(): array
    {
        if (! $this->canView('transfers')) {
            return ['categories' => [], 'currencies' => []];
        }

        $start = CarbonImmutable::today()->startOfMonth()->subMonths(11);
        $months = collect(range(0, 11))->map(fn (int $offset): CarbonImmutable => $start->addMonths($offset));

        $rows = TransfersTracking::query()
            ->select(['receive_date', 'amount_received_currency', 'net_amount_received'])
            ->where('ar_status', ArStatus::Received)
            ->whereBetween('receive_date', [$start->toDateString(), CarbonImmutable::today()->toDateString()])
            ->get();

        $currencies = $rows
            ->groupBy('amount_received_currency')
            ->sortKeys()
            ->map(function (Collection $currencyRows, string $currency) use ($months): array {
                $perMonth = $currencyRows->groupBy(fn (TransfersTracking $row): string => $row->receive_date->format('Y-m'));
                $data = $months->map(fn (CarbonImmutable $month): float => round((float) ($perMonth->get($month->format('Y-m'))?->sum('net_amount_received') ?? 0), 2))->all();

                return ['currency' => $currency, 'data' => $data, 'total' => array_sum($data)];
            })
            ->values()
            ->all();

        return [
            'categories' => $months->map(fn (CarbonImmutable $month): string => $month->translatedFormat(__('formats.month_short')))->all(),
            'currencies' => $currencies,
        ];
    }
}
